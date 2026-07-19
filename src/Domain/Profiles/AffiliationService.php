<?php

namespace Spoome\Domain\Profiles;

use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Notifications\NotificationService;

/**
 * Affiliazioni atleta↔organizzazione (la keystone del network sportivo). Conferma BILATERALE:
 * una parte propone (`pending`, requested_by = sé), l'altra conferma (`confirmed`). Solo `confirmed`
 * è visibile su entrambe le pagine (valore di verifica sociale).
 *
 * SICUREZZA (authz al livello dati, defense-in-depth):
 *  - $actingPid è già validato dal controller via ActingContext::canActAs (l'utente PUÒ agire come
 *    quel profilo). Qui si verifica in più che l'acting sia EFFETTIVAMENTE una parte dell'affiliazione:
 *    non si può creare un'affiliazione tra due terzi, né confermare quella altrui.
 *  - Confermare può SOLO la parte destinataria (chi non ha richiesto). Rimuovere/rifiutare: una parte.
 *  - Il lato org deve essere davvero `is_organization=1` (niente affiliazioni verso una persona come "org").
 * Ogni scrittura è rate-limited. PDO: placeholder distinti nel repository.
 */
final class AffiliationService
{
    private const MAX_ACTIONS = 40;
    private const WINDOW_MIN   = 10;

    private AffiliationRepository $repo;
    private ProfileRepository $profiles;
    private RateLimiter $limiter;
    private NotificationService $notifications;

    public function __construct(
        ?AffiliationRepository $repo = null,
        ?ProfileRepository $profiles = null,
        ?RateLimiter $limiter = null,
        ?NotificationService $notifications = null
    ) {
        $this->repo          = $repo ?? new AffiliationRepository();
        $this->profiles      = $profiles ?? new ProfileRepository();
        $this->limiter       = $limiter ?? new RateLimiter();
        $this->notifications = $notifications ?? new NotificationService();
    }

    /**
     * Propone un'affiliazione. $actingPid deve essere il membro o l'org (chi inizia). L'altro conferma.
     * @param array<string,mixed> $input campi grezzi (role/team/jersey/start_year/end_year/is_current)
     */
    public function request(int $actingPid, int $memberPid, int $orgPid, array $input, string $ip = 'unknown'): ServiceResult
    {
        if ($memberPid === $orgPid) {
            return ServiceResult::fail(I18n::t('affil.error.self'), 422);
        }
        if ($actingPid !== $memberPid && $actingPid !== $orgPid) {
            return ServiceResult::fail(I18n::t('affil.error.forbidden'), 403);
        }
        if ($this->limiter->tooManyByKey('affil:' . $actingPid, self::MAX_ACTIONS, self::WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('affil.error.throttled'), 429);
        }

        $org    = $this->profiles->findEnrichedById($orgPid);
        $member = $this->profiles->findEnrichedById($memberPid);
        if ($org === null || $member === null) {
            return ServiceResult::fail(I18n::t('affil.error.not_found'), 404);
        }
        if (empty($org['is_organization'])) {
            return ServiceResult::fail(I18n::t('affil.error.not_org'), 422);
        }
        // Modello di affiliazione a due livelli:
        //  - PERSONA → org: atleta militante in società/associazione/federazione (sempre consentito).
        //  - SOCIETÀ/ASSOCIAZIONE → FEDERAZIONE: org affiliata a una federazione/lega (org↔org).
        //  - Una FEDERAZIONE è il vertice: non può essere "membro" di nessuno.
        $orgTypeKey    = (string) ($org['type_key'] ?? '');
        $memberTypeKey = (string) ($member['type_key'] ?? '');
        if ($memberTypeKey === 'federazione') {
            return ServiceResult::fail(I18n::t('affil.error.fed_no_member'), 422);
        }
        if (!empty($member['is_organization']) && $orgTypeKey !== 'federazione') {
            return ServiceResult::fail(I18n::t('affil.error.org_only_to_fed'), 422);
        }

        $existing = $this->repo->findPair($memberPid, $orgPid);
        if ($existing !== null) {
            $code = $existing['status'] === 'confirmed' ? 'affil.error.exists_confirmed' : 'affil.error.exists_pending';
            return ServiceResult::fail(I18n::t($code), 422);
        }

        $id = $this->repo->insertPending($memberPid, $orgPid, $actingPid, $this->sanitize($input));
        $this->limiter->hit('affil:' . $actingPid, $ip);

        // Notifica la parte che deve confermare (quella diversa dall'acting).
        $confirmerPid = $actingPid === $memberPid ? $orgPid : $memberPid;
        $this->notifications->affiliationRequested($actingPid, $confirmerPid);

        return ServiceResult::ok(['id' => $id, 'status' => 'pending'], [], 201);
    }

    /** Conferma una pending: solo la parte destinataria (chi NON ha richiesto). */
    public function confirm(int $actingPid, int $affId, string $ip = 'unknown'): ServiceResult
    {
        $row = $this->repo->findById($affId);
        if ($row === null) {
            return ServiceResult::fail(I18n::t('affil.error.not_found'), 404);
        }
        $memberPid = (int) $row['member_profile_id'];
        $orgPid    = (int) $row['org_profile_id'];
        $requester = (int) $row['requested_by_profile_id'];
        $confirmerPid = $requester === $memberPid ? $orgPid : $memberPid;

        if ($actingPid !== $confirmerPid) {
            return ServiceResult::fail(I18n::t('affil.error.forbidden'), 403);
        }
        if ($row['status'] !== 'pending') {
            return ServiceResult::fail(I18n::t('affil.error.not_pending'), 422);
        }
        if ($this->limiter->tooManyByKey('affil:' . $actingPid, self::MAX_ACTIONS, self::WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('affil.error.throttled'), 429);
        }
        if (!$this->repo->confirm($affId)) {
            return ServiceResult::fail(I18n::t('affil.error.not_pending'), 422);
        }
        $this->limiter->hit('affil:' . $actingPid, $ip);
        $this->notifications->affiliationConfirmed($actingPid, $requester);

        return ServiceResult::ok(['id' => $affId, 'status' => 'confirmed']);
    }

    /**
     * Rimuove un'affiliazione: rifiuto di una pending o rimozione di una confermata.
     * Authz: l'acting deve essere una delle due parti (atleta o org). Un terzo → 403.
     */
    public function remove(int $actingPid, int $affId, string $ip = 'unknown'): ServiceResult
    {
        $row = $this->repo->findById($affId);
        if ($row === null) {
            return ServiceResult::fail(I18n::t('affil.error.not_found'), 404);
        }
        $memberPid = (int) $row['member_profile_id'];
        $orgPid    = (int) $row['org_profile_id'];
        if ($actingPid !== $memberPid && $actingPid !== $orgPid) {
            return ServiceResult::fail(I18n::t('affil.error.forbidden'), 403);
        }
        if ($this->limiter->tooManyByKey('affil:' . $actingPid, self::MAX_ACTIONS, self::WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('affil.error.throttled'), 429);
        }
        $this->repo->delete($affId);
        $this->limiter->hit('affil:' . $actingPid, $ip);

        return ServiceResult::noContent();
    }

    /**
     * Normalizza/limita i campi descrittivi (whitelist). Output: forma pronta per il repository.
     * @param array<string,mixed> $in
     * @return array{role:?string,team:?string,jersey:?string,start_year:?int,end_year:?int,is_current:int}
     */
    private function sanitize(array $in): array
    {
        $str = static function ($v, int $max): ?string {
            $v = trim((string) ($v ?? ''));
            if ($v === '') {
                return null;
            }
            return mb_substr($v, 0, $max);
        };
        $year = static function ($v): ?int {
            if ($v === null || $v === '' || !is_numeric($v)) {
                return null;
            }
            $y = (int) $v;
            return ($y >= 1900 && $y <= 2100) ? $y : null;
        };

        return [
            'role'       => $str($in['role'] ?? null, 80),
            'team'       => $str($in['team'] ?? null, 80),
            'jersey'     => $str($in['jersey'] ?? null, 10),
            'start_year' => $year($in['start_year'] ?? null),
            'end_year'   => $year($in['end_year'] ?? null),
            'is_current' => !empty($in['is_current']) && $in['is_current'] !== '0' ? 1 : 0,
        ];
    }
}
