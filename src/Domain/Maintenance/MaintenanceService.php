<?php

namespace Spoome\Domain\Maintenance;

use PDO;
use Spoome\Core\Db;
use Spoome\Core\Logger;
use Spoome\Domain\Auth\EmailVerificationService;
use Spoome\Domain\Auth\PasswordResetService;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Auth\TokenService;
use Spoome\Domain\Events\EventRepository;
use Spoome\Domain\Links\LinkPreviewRepository;
use Spoome\Domain\Notifications\NotificationRepository;

/**
 * Manutenzione schedulabile (cron SiteGround via jobs/maintenance.php):
 *  1) PURGE — pulisce le tabelle che altrimenti crescono illimitate (GDPR + costo storage/indice):
 *     tentativi di login vecchi, token scaduti/revocati/usati, log applicativi e eventi oltre retention,
 *     cache anteprime link scadute. Ogni DELETE è parametrizzato e cancella a BATCH (LIMIT in loop)
 *     per non tenere lock lunghi.
 *  2) RECONCILE — riallinea i contatori denormalizzati alla source-of-truth (COUNT). Il mantenimento
 *     incrementale è già atomico e live (drift atteso = 0); questa è difesa in profondità, NON distruttiva:
 *     se un percorso sfuggisse, il drift si auto-ripara. rowCount() (senza CLIENT_FOUND_ROWS) riporta
 *     SOLO le righe effettivamente cambiate = drift corretto.
 *
 * Idempotente e sicuro a ri-esecuzione: rieseguirlo non fa danni (le condizioni sono sempre le stesse).
 */
final class MaintenanceService
{
    /** Finestre di retention (default; sovrascrivibili dal chiamante). */
    public const LOGIN_ATTEMPTS_HOURS = 720;  // ~30 giorni
    public const APP_LOGS_DAYS        = 90;
    public const USER_EVENTS_DAYS     = 30;
    public const NOTIFICATIONS_DAYS   = 90;   // solo notifiche già lette

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /**
     * Esegue purge + reconcile e ritorna il riepilogo per lo stdout del job.
     * @return array{purged:array<string,int>, reconciled:array<string,int>}
     */
    public function run(int $batch = 5000): array
    {
        return [
            'purged'     => $this->purge($batch),
            'reconciled' => $this->reconcileCounters(),
        ];
    }

    /**
     * Pulizia delle tabelle a crescita illimitata. Ogni voce è la delega al metodo di dominio
     * competente (single source of truth della query), così la logica resta testabile e riusabile.
     * @return array<string,int> tabella => righe eliminate
     */
    public function purge(int $batch = 5000): array
    {
        return [
            'login_attempts'      => (new RateLimiter($this->pdo))->purgeOlderThan(self::LOGIN_ATTEMPTS_HOURS, $batch),
            'auth_tokens'         => (new TokenService($this->pdo))->purgeExpired($batch),
            'email_verifications' => (new EmailVerificationService($this->pdo))->purgeStale($batch),
            'password_resets'     => (new PasswordResetService($this->pdo))->purgeStale($batch),
            'app_logs'            => Logger::purge(self::APP_LOGS_DAYS, $batch),
            'user_events'         => (new EventRepository($this->pdo))->purgeOlderThan(self::USER_EVENTS_DAYS, $batch),
            'link_previews'       => (new LinkPreviewRepository($this->pdo))->purgeExpired($batch),
            'notifications'       => (new NotificationRepository($this->pdo))->purgeRead(self::NOTIFICATIONS_DAYS, $batch),
        ];
    }

    /**
     * Riallinea i contatori denormalizzati dalla source-of-truth. Non distruttivo: aggiorna solo
     * i valori che divergono. Le query rispecchiano esattamente il backfill della migrazione 0014
     * (+ likes/comments dei post, mig. 0015). rowCount() = righe drift-ate corrette.
     * @return array<string,int> contatore => righe riallineate
     */
    public function reconcileCounters(): array
    {
        $out = [];

        $out['profiles.followers_count'] = $this->exec(
            'UPDATE profiles p SET p.followers_count =
                (SELECT COUNT(*) FROM follows f WHERE f.followee_id = p.id)'
        );
        $out['profiles.following_count'] = $this->exec(
            'UPDATE profiles p SET p.following_count =
                (SELECT COUNT(*) FROM follows f WHERE f.follower_id = p.id)'
        );
        $out['profiles.connections_count'] = $this->exec(
            "UPDATE profiles p SET p.connections_count =
                (SELECT COUNT(*) FROM connections c WHERE c.status='accepted'
                 AND (c.requester_id = p.id OR c.addressee_id = p.id))"
        );
        $out['profiles.unread_messages'] = $this->exec(
            'UPDATE profiles p SET p.unread_messages =
                (SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id
                 WHERE (c.profile_a_id = p.id OR c.profile_b_id = p.id)
                   AND m.sender_id <> p.id AND m.read_at IS NULL)'
        );
        $out['users.unread_notifications'] = $this->exec(
            'UPDATE users u SET u.unread_notifications =
                (SELECT COUNT(*) FROM notifications n WHERE n.user_id = u.id AND n.read_at IS NULL)'
        );
        $out['posts.likes_count'] = $this->exec(
            'UPDATE posts p SET p.likes_count =
                (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id)'
        );
        $out['posts.comments_count'] = $this->exec(
            'UPDATE posts p SET p.comments_count =
                (SELECT COUNT(*) FROM post_comments c WHERE c.post_id = p.id)'
        );

        return $out;
    }

    /** Esegue una UPDATE statica (nessun input esterno) e ritorna le righe cambiate. */
    private function exec(string $sql): int
    {
        return (int) $this->pdo->exec($sql);
    }
}
