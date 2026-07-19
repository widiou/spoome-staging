<?php

namespace Spoome\Domain\Notifications;

use PDO;
use Spoome\Core\Db;

/**
 * Notifiche in-app. Riusabile per qualsiasi evento (claim, follow, connessioni, DM…):
 * chi genera l'evento chiama `create()`, la UI legge `unreadCount()`/`recent()`.
 */
final class NotificationRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    public function create(int $userId, string $type, string $title, ?string $body = null, ?string $url = null): int
    {
        // Atomico: INSERT notifica + contatore denormalizzato nella STESSA transazione (no drift).
        $id = Db::transaction($this->pdo, function (PDO $pdo) use ($userId, $type, $title, $body, $url): int {
            $stmt = $pdo->prepare(
                'INSERT INTO notifications (user_id, type, title, body, url) VALUES (:u, :t, :ti, :b, :url)'
            );
            $stmt->execute([
                'u'   => $userId,
                't'   => mb_substr($type, 0, 40),
                'ti'  => mb_substr($title, 0, 200),
                'b'   => $body !== null ? mb_substr($body, 0, 500) : null,
                'url' => $url !== null ? mb_substr($url, 0, 255) : null,
            ]);
            // Cattura l'id PRIMA di altre query (l'UPDATE non azzera lastInsertId, ma è la prassi sicura).
            $nid = (int) $pdo->lastInsertId();
            // Contatore denormalizzato (badge nav): +1 non-letta.
            $pdo->prepare('UPDATE users SET unread_notifications = unread_notifications + 1 WHERE id = :u')
                ->execute(['u' => $userId]);
            return $nid;
        });

        // Evento realtime (additivo, SOFT): scrive l'inbox del canale dell'utente destinatario.
        // DOPO il commit, fuori dalla transazione: fire-and-forget, un fallimento non deve MAI
        // rompere/rallentare la notifica né far rollback dell'INSERT già committato.
        try {
            (new \Spoome\Domain\Events\EventBus($this->pdo))->emit($userId, 'notification.created', null, [
                'notification_id' => $id,
                'type'            => mb_substr($type, 0, 40),
                'title'           => mb_substr($title, 0, 200),
                'body'            => $body !== null ? mb_substr($body, 0, 200) : null,
                'url'             => $url !== null ? mb_substr($url, 0, 255) : null,
            ]);
        } catch (\Throwable $e) {
            \Spoome\Core\Logger::error('event notification.created failed', ['exception' => $e->getMessage()]);
        }

        return $id;
    }

    /**
     * True se esiste già una notifica identica (stesso destinatario, tipo e corpo — il corpo
     * contiene il nome dell'attore) nelle ultime $hours ore. Usato per deduplicare l'anti-spam
     * (es. like ripetuti dello stesso profilo).
     */
    public function existsRecentSame(int $userId, string $type, string $body, int $hours): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM notifications
             WHERE user_id = :u AND type = :t AND body = :b AND created_at > (NOW() - INTERVAL :h HOUR)
             LIMIT 1'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':t', mb_substr($type, 0, 40));
        $stmt->bindValue(':b', mb_substr($body, 0, 500));
        $stmt->bindValue(':h', $hours, PDO::PARAM_INT);
        $stmt->execute();
        return (bool) $stmt->fetchColumn();
    }

    /** Badge nav: legge il contatore denormalizzato invece di un COUNT(*) live. */
    public function unreadCount(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT unread_notifications FROM users WHERE id = :u');
        $stmt->execute(['u' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $userId, int $limit = 30): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, type, title, body, url, read_at, created_at
             FROM notifications WHERE user_id = :u ORDER BY id DESC LIMIT :lim'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function markAllRead(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = :u AND read_at IS NULL');
        $stmt->execute(['u' => $userId]);
        // Contatore denormalizzato azzerato (tutte lette).
        $this->pdo->prepare('UPDATE users SET unread_notifications = 0 WHERE id = :u')->execute(['u' => $userId]);
    }

    /**
     * Purge di manutenzione: elimina le notifiche GIÀ LETTE più vecchie di $days giorni (le non-lette
     * restano, non alterano i contatori). Conservativo e batchato (LIMIT in loop) per non tenere lock
     * lunghi. A crescita social `notifications` è tra le tabelle più veloci → retention necessaria.
     * @return int righe eliminate
     */
    public function purgeRead(int $days = 90, int $batch = 5000): int
    {
        $days  = max(1, $days);
        $batch = max(1, min($batch, 50000));
        $total = 0;
        do {
            $stmt = $this->pdo->prepare(
                'DELETE FROM notifications WHERE read_at IS NOT NULL
                 AND created_at < (NOW() - INTERVAL :d DAY) LIMIT :lim'
            );
            $stmt->bindValue(':d', $days, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $batch, PDO::PARAM_INT);
            $stmt->execute();
            $n = $stmt->rowCount();
            $total += $n;
        } while ($n === $batch);
        return $total;
    }
}
