<?php

namespace Spoome\Domain\Opportunities;

use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Core\Validator;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Domain\Sports\SportRepository;

/**
 * Pubblicazione e gestione delle Opportunities. Unica sede di validazione + authz + rate-limit
 * (contratto ServiceResult condiviso da web e API). MVP SENZA pagamenti.
 *
 * AUTHZ AL LIVELLO DATI (MASSIMO, defense-in-depth):
 *  - $actingPid arriva GIÀ validato dal controller (ActingContext::resolveForWrite('admin') → l'utente
 *    può agire come quel profilo). Qui si verifica in PIÙ che l'acting sia un'ORGANIZZAZIONE
 *    (is_organization=1) e VERIFICATA (verified_at, badge M3): solo società/associazioni/federazioni
 *    verificate pubblicano opportunità — gate di fiducia disegnato da Bianca ("verifica la pagina per
 *    pubblicare"), la bacheca parte sicura.
 *  - chiudere un'opportunità: SOLO l'org che l'ha pubblicata (org_profile_id === actingPid).
 */
final class OpportunityService
{
    /**
     * Whitelist del tipo di opportunità (`kind`). VOLUTAMENTE applicativa (non un ENUM di schema):
     * estenderla/rinominarla è una riga qui, ZERO migrazione. Valori PROVVISORI e sport-generici:
     * NON esiste "provino" hardcodato. Il vocabolario definitivo (e l'eventuale semantica A/B —
     * mercato-trasferimenti vs job-board tecnico) lo fissa M6; le label sono in `lang/it.php`.
     */
    public const KINDS = ['selection', 'training_camp', 'seasonal_engagement', 'technical_role', 'other'];

    private const MAX_ACTIONS = 20;
    private const WINDOW_MIN   = 10;

    private OpportunityRepository $repo;
    private ProfileRepository $profiles;
    private SportRepository $sports;
    private RateLimiter $limiter;

    public function __construct(
        ?OpportunityRepository $repo = null,
        ?ProfileRepository $profiles = null,
        ?SportRepository $sports = null,
        ?RateLimiter $limiter = null
    ) {
        $this->repo     = $repo ?? new OpportunityRepository();
        $this->profiles = $profiles ?? new ProfileRepository();
        $this->sports   = $sports ?? new SportRepository();
        $this->limiter  = $limiter ?? new RateLimiter();
    }

    /**
     * Pubblica un'opportunità a nome dell'org $actingPid. $userId = utente reale che agisce (audit).
     * @param array<string,mixed> $input campi grezzi (title, kind, sport_id|sport_slug, location, description, event_date, deadline)
     */
    public function publish(int $actingPid, ?int $userId, array $input, string $ip = 'unknown'): ServiceResult
    {
        $org = $this->profiles->findEnrichedById($actingPid);
        if ($org === null) {
            return ServiceResult::fail(I18n::t('opp.error.not_found'), 404);
        }
        if (empty($org['is_organization'])) {
            return ServiceResult::fail(I18n::t('opp.error.not_org'), 403);
        }
        // Gate di fiducia (M3 · Verification-da-club): SOLO le organizzazioni VERIFICATE pubblicano.
        // `verified_at` su profiles è la stessa fonte del badge di M3 (deployato nello stesso blocco):
        // la bacheca parte sicura, niente spam da pagine non verificate.
        if (empty($org['verified_at'])) {
            return ServiceResult::fail(I18n::t('opp.error.org_unverified'), 403);
        }

        if ($this->limiter->tooManyByKey('opp:publish:' . $actingPid, self::MAX_ACTIONS, self::WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('opp.error.throttled'), 429);
        }

        $v = Validator::make($input, [
            'title'       => 'required|min:3|max:160',
            'kind'        => 'required|in:' . implode(',', self::KINDS),
            'description' => 'required|min:10|max:5000',
        ]);
        if ($v->fails()) {
            return ServiceResult::fromValidator($v);
        }

        $sportId = $this->resolveSportId($input);
        if ($sportId === false) {
            return ServiceResult::fail(I18n::t('opp.error.bad_sport'), 422, ['sport_id' => I18n::t('opp.error.bad_sport')]);
        }
        $eventDate = $this->normalizeDate($input['event_date'] ?? null);
        $deadline  = $this->normalizeDate($input['deadline'] ?? null);
        if ($eventDate === false || $deadline === false) {
            return ServiceResult::fail(I18n::t('opp.error.bad_date'), 422);
        }

        $data = [
            'title'           => trim((string) $input['title']),
            'kind'            => (string) $input['kind'],
            'sport_id'        => $sportId,
            'location_region' => $this->str($input['location_region'] ?? null, 80),
            'location_city'   => $this->str($input['location_city'] ?? null, 120),
            'description'     => trim((string) $input['description']),
            'event_date'      => $eventDate,
            'deadline'        => $deadline,
        ];

        $id = $this->repo->create($actingPid, $userId, $data);
        $this->limiter->hit('opp:publish:' . $actingPid, $ip);

        return ServiceResult::ok(['id' => $id, 'status' => 'open'], ['message' => I18n::t('opp.done.published')], 201);
    }

    /** Chiude un'opportunità: solo l'org che l'ha pubblicata. */
    public function close(int $actingPid, int $oppId, string $ip = 'unknown'): ServiceResult
    {
        $opp = $this->repo->findById($oppId);
        if ($opp === null) {
            return ServiceResult::fail(I18n::t('opp.error.not_found'), 404);
        }
        if ((int) $opp['org_profile_id'] !== $actingPid) {
            return ServiceResult::fail(I18n::t('opp.error.forbidden'), 403);
        }
        if ($this->limiter->tooManyByKey('opp:manage:' . $actingPid, self::MAX_ACTIONS, self::WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('opp.error.throttled'), 429);
        }
        if (!$this->repo->close($oppId)) {
            return ServiceResult::fail(I18n::t('opp.error.already_closed'), 422);
        }
        $this->limiter->hit('opp:manage:' . $actingPid, $ip);

        return ServiceResult::ok(['id' => $oppId, 'status' => 'closed'], ['message' => I18n::t('opp.done.closed')]);
    }

    /* ------------------------------------------------------------ helpers ---- */

    /**
     * Risolve la disciplina: accetta `sport_id` (int) o `sport_slug` (string). Ritorna l'id valido,
     * null se non specificata, o false se specificata ma inesistente (→ 422, mai FK-error 500).
     * @param array<string,mixed> $input
     * @return int|null|false
     */
    private function resolveSportId(array $input): int|null|false
    {
        $slug = trim((string) ($input['sport_slug'] ?? ''));
        if ($slug !== '') {
            return $this->sports->idBySlug($slug) ?? false;
        }
        $raw = $input['sport_id'] ?? null;
        if ($raw === null || $raw === '' || (int) $raw === 0) {
            return null;
        }
        $id = (int) $raw;
        foreach ($this->sports->all() as $s) {
            if ((int) $s['id'] === $id) {
                return $id;
            }
        }
        return false;
    }

    /**
     * Normalizza una data ISO (YYYY-MM-DD): null se vuota, false se presente ma non valida.
     * @return string|null|false
     */
    private function normalizeDate(mixed $value): string|null|false
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        if ($d === false || $d->format('Y-m-d') !== $s) {
            return false;
        }
        return $s;
    }

    private function str(mixed $value, int $max): ?string
    {
        $s = trim((string) ($value ?? ''));
        return $s === '' ? null : mb_substr($s, 0, $max);
    }
}
