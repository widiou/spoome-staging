<?php

namespace Spoome\Domain\Profiles;

use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Support\Str;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Connections\ConnectionRepository;
use Spoome\Domain\Notifications\NotificationService;

/**
 * Raccomandazioni (testimonial LinkedIn-style) tra profili connessi. Distinte dagli endorsement:
 * testo libero che una persona scrive per un'altra, pubblicato SOLO dopo l'approvazione del
 * destinatario.
 *
 * v1 persona→persona:
 *  - AUTORE = profilo personale dell'utente (il controller passa il personal acting id).
 *  - DESTINATARIO = profilo NON-organizzazione (persona). L'org come destinatario è bloccata qui.
 * Authz a più livelli (defense-in-depth):
 *  - self-reco vietata (author !== recipient);
 *  - autore e destinatario devono essere CONNESSI (ConnectionRepository::areConnected);
 *  - le mutazioni del destinatario (accept/hide) sono scoping-ate per recipient_profile_id nel repo,
 *    e in più l'utente deve possedere quel profilo personale.
 * Rate-limit per autore/destinatario. Il testo è conservato grezzo (niente HTML): l'output passa
 * SEMPRE da e()/nl2br(e()) nelle view e da JSON-encode nell'API — mai sanitizzato in scrittura.
 * Restituisce sempre ServiceResult.
 */
final class RecommendationService
{
    private const BODY_MAX = 1000;
    private const REL_MAX  = 80;

    // Throttle scritture (write/accept/hide): chiave "reco:{profileId}", parità con endorse/affiliation.
    private const WRITE_MAX    = 20;   // colpi
    private const WRITE_WINDOW = 10;   // minuti

    private RecommendationRepository $repo;
    private ProfileRepository $profiles;
    private ConnectionRepository $connections;
    private RateLimiter $limiter;
    private NotificationService $notifications;

    public function __construct(
        ?RecommendationRepository $repo = null,
        ?ProfileRepository $profiles = null,
        ?ConnectionRepository $connections = null,
        ?RateLimiter $limiter = null,
        ?NotificationService $notifications = null
    ) {
        $this->repo          = $repo ?? new RecommendationRepository();
        $this->profiles      = $profiles ?? new ProfileRepository();
        $this->connections   = $connections ?? new ConnectionRepository();
        $this->limiter       = $limiter ?? new RateLimiter();
        $this->notifications = $notifications ?? new NotificationService();
    }

    /* ----------------------------------------------------------------- scrittura ---- */

    /**
     * Scrive (o riscrive) la raccomandazione dell'autore per il destinatario. Nasce `pending`;
     * riscrivere torna `pending` (upsert). Notifica il destinatario.
     */
    public function write(int $authorActingProfileId, int $recipientProfileId, string $body, ?string $relationship, string $ip = 'unknown'): ServiceResult
    {
        if ($authorActingProfileId === $recipientProfileId) {
            return ServiceResult::fail(I18n::t('reco.error.self'), 422);
        }

        // Il destinatario deve esistere ed essere una PERSONA (non-org). v1 persona→persona.
        $recipient = $this->profiles->findEnrichedById($recipientProfileId);
        if ($recipient === null) {
            return ServiceResult::fail(I18n::t('reco.error.not_found'), 404);
        }
        if (!empty($recipient['is_organization'])) {
            return ServiceResult::fail(I18n::t('reco.error.recipient_org'), 422);
        }

        // Solo le connessioni possono raccomandare (verso-agnostico, status='accepted').
        if (!$this->connections->areConnected($authorActingProfileId, $recipientProfileId)) {
            return ServiceResult::fail(I18n::t('reco.error.not_connected'), 403);
        }

        $body = trim($body);
        if ($body === '') {
            return ServiceResult::fail(I18n::t('reco.error.empty'), 422, ['body' => I18n::t('reco.error.empty')]);
        }
        $body = Str::clamp($body, self::BODY_MAX);
        $relationship = $this->normalizeRelationship($relationship);

        if ($this->limiter->tooManyByKey('reco:' . $authorActingProfileId, self::WRITE_MAX, self::WRITE_WINDOW)) {
            return ServiceResult::fail(I18n::t('reco.error.throttled'), 429);
        }

        $id = $this->repo->upsert($authorActingProfileId, $recipientProfileId, $body, $relationship);
        $this->limiter->hit('reco:' . $authorActingProfileId, $ip);
        $this->notifications->recommendationReceived($authorActingProfileId, $recipientProfileId);

        return ServiceResult::ok(['id' => $id, 'status' => 'pending'], [], 201);
    }

    /* --------------------------------------------------- gestione del destinatario ---- */

    /** Il destinatario approva la raccomandazione → `visible` (pubblica). Notifica l'autore. Idempotente. */
    public function accept(int $recipientUserId, int $recId, string $ip = 'unknown'): ServiceResult
    {
        return $this->respond($recipientUserId, $recId, 'visible', $ip);
    }

    /** Il destinatario rifiuta una pending o nasconde una visible → `hidden`. Idempotente. */
    public function hide(int $recipientUserId, int $recId, string $ip = 'unknown'): ServiceResult
    {
        return $this->respond($recipientUserId, $recId, 'hidden', $ip);
    }

    /**
     * Cambio stato guidato dal DESTINATARIO. Ownership: l'utente deve possedere il profilo personale
     * che è il destinatario della raccomandazione; il repo scoping-a comunque per recipient_profile_id.
     */
    private function respond(int $recipientUserId, int $recId, string $status, string $ip): ServiceResult
    {
        $rec = $this->repo->find($recId);
        if ($rec === null) {
            return ServiceResult::fail(I18n::t('reco.error.not_found'), 404);
        }
        $recipientPid = (int) $rec['recipient_profile_id'];

        // Risolvo il profilo personale dell'utente: deve coincidere col destinatario della reco.
        $personal = $this->profiles->personalOrAny($recipientUserId);
        if ($personal === null || $personal->id !== $recipientPid) {
            return ServiceResult::fail(I18n::t('reco.error.forbidden'), 403);
        }

        // Idempotente: già nello stato voluto → nessuna rinotifica.
        if ((string) $rec['status'] === $status) {
            return ServiceResult::ok(['id' => $recId, 'status' => $status]);
        }

        if ($this->limiter->tooManyByKey('reco:' . $recipientPid, self::WRITE_MAX, self::WRITE_WINDOW)) {
            return ServiceResult::fail(I18n::t('reco.error.throttled'), 429);
        }
        if (!$this->repo->setStatus($recId, $recipientPid, $status)) {
            return ServiceResult::fail(I18n::t('reco.error.not_found'), 404);
        }
        $this->limiter->hit('reco:' . $recipientPid, $ip);

        if ($status === 'visible') {
            // actor = destinatario che accetta; si notifica l'autore (la sua reco è ora pubblica).
            $this->notifications->recommendationAccepted($recipientPid, (int) $rec['author_profile_id']);
        }

        return ServiceResult::ok(['id' => $recId, 'status' => $status]);
    }

    /* --------------------------------------------------------------- read-model ---- */

    /** @return array<int,array<string,mixed>> raccomandazioni visibili ricevute (con autore). */
    public function visibleFor(int $profileId): array
    {
        return $this->repo->visibleFor($profileId);
    }

    /** @return array<int,array<string,mixed>> raccomandazioni in attesa ricevute (con autore). */
    public function pendingFor(int $profileId): array
    {
        return $this->repo->pendingFor($profileId);
    }

    /** @return array<int,array<string,mixed>> raccomandazioni scritte dal profilo (con destinatario). */
    public function writtenBy(int $profileId): array
    {
        return $this->repo->writtenBy($profileId);
    }

    /* ------------------------------------------------------------------ helper ---- */

    /** trim + collassa spazi + clamp; stringa vuota → null (relationship è opzionale). */
    private function normalizeRelationship(?string $relationship): ?string
    {
        if ($relationship === null) {
            return null;
        }
        $rel = trim((string) preg_replace('/\s+/u', ' ', $relationship));
        if ($rel === '') {
            return null;
        }
        return Str::clamp($rel, self::REL_MAX);
    }
}
