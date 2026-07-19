<?php

namespace Spoome\Domain\Connections;

use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Feed\ActivityRepository;
use Spoome\Domain\Notifications\NotificationService;

/**
 * Regole delle connessioni reciproche. Due sole azioni pubbliche:
 *  - connect():    richiedi-o-accetta (crea pending, oppure accetta una richiesta in entrata già esistente)
 *  - disconnect(): annulla/rifiuta/rimuovi (elimina qualunque relazione tra i due)
 * Vincoli: niente auto-connessione, niente profili privati, rate-limit. Riusato da Web e API.
 */
final class ConnectionService
{
    public const NONE = 'none';
    public const PENDING_OUT = 'pending_out';
    public const PENDING_IN = 'pending_in';
    public const CONNECTED = 'connected';

    private const MAX_ACTIONS = 40;
    private const WINDOW_MIN = 10;

    private ConnectionRepository $repo;
    private RateLimiter $limiter;
    private ActivityRepository $activities;
    private NotificationService $notifications;

    public function __construct(?ConnectionRepository $repo = null, ?RateLimiter $limiter = null, ?ActivityRepository $activities = null, ?NotificationService $notifications = null)
    {
        $this->repo = $repo ?? new ConnectionRepository();
        $this->limiter = $limiter ?? new RateLimiter();
        $this->activities = $activities ?? new ActivityRepository();
        $this->notifications = $notifications ?? new NotificationService();
    }

    /** Richiedi connessione, o accetta se il target ci aveva già richiesto. */
    public function connect(int $actorId, int $targetId, string $targetVisibility, string $targetName = '', string $ip = 'unknown'): ServiceResult
    {
        if ($targetId === $actorId) {
            return ServiceResult::fail(I18n::t('connect.error.self'), 422);
        }
        if ($targetVisibility === 'private') {
            return ServiceResult::fail(I18n::t('connect.error.private'), 403);
        }
        if ($this->limiter->tooManyByKey('conn:' . $actorId, self::MAX_ACTIONS, self::WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('connect.error.throttled'), 429);
        }

        $row = $this->repo->findBetween($actorId, $targetId);
        if ($row === null) {
            $this->repo->insertPending($actorId, $targetId);
            $this->limiter->hit('conn:' . $actorId, $ip);
            $this->notifications->connectionRequest($actorId, $targetId);
        } elseif ($row['status'] !== 'accepted' && (int) $row['addressee_id'] === $actorId) {
            // esiste una richiesta in entrata target→actor: accettala → evento "connessione".
            $this->repo->accept((int) $row['requester_id'], $actorId);
            $this->limiter->hit('conn:' . $actorId, $ip);
            $this->activities->record($actorId, ActivityRepository::CONNECTED, $targetId, $targetName);
            $this->notifications->connectionAccepted($actorId, $targetId); // notifica il richiedente
        }
        // altrimenti: già connessi o richiesta in uscita già pendente → no-op idempotente.

        return ServiceResult::ok($this->state($actorId, $targetId));
    }

    /** Annulla la propria richiesta, rifiuta quella in entrata, o rimuove la connessione: elimina la relazione. */
    public function disconnect(int $actorId, int $targetId, string $ip = 'unknown'): ServiceResult
    {
        if ($this->limiter->tooManyByKey('conn:' . $actorId, self::MAX_ACTIONS, self::WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('connect.error.throttled'), 429);
        }
        $this->repo->deleteBetween($actorId, $targetId);
        $this->limiter->hit('conn:' . $actorId, $ip);
        return ServiceResult::ok($this->state($actorId, $targetId));
    }

    /** Stato della relazione dal punto di vista dell'attore. */
    public function statusFrom(int $actorId, int $targetId): string
    {
        $row = $this->repo->findBetween($actorId, $targetId);
        if ($row === null) {
            return self::NONE;
        }
        if ($row['status'] === 'accepted') {
            return self::CONNECTED;
        }
        return (int) $row['requester_id'] === $actorId ? self::PENDING_OUT : self::PENDING_IN;
    }

    private function state(int $actorId, int $targetId): array
    {
        return [
            'status'            => $this->statusFrom($actorId, $targetId),
            'connections_count' => $this->repo->connectionCount($targetId),
        ];
    }
}
