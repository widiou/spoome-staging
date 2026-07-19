<?php

namespace Spoome\Domain\Messaging;

use PDO;
use Spoome\Core\Db;

/**
 * Messaggi delle conversazioni. `read_at` traccia la lettura da parte del destinatario.
 */
final class MessageRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    public function create(int $conversationId, int $senderId, string $body): int
    {
        // Atomico: INSERT messaggio + contatore non-letti nella STESSA transazione (no drift).
        return Db::transaction($this->pdo, function (PDO $pdo) use ($conversationId, $senderId, $body): int {
            $stmt = $pdo->prepare(
                'INSERT INTO messages (conversation_id, sender_id, body) VALUES (:c, :s, :b)'
            );
            $stmt->execute(['c' => $conversationId, 's' => $senderId, 'b' => $body]);
            // Cattura l'id PRIMA dell'UPDATE: una query successiva azzera lastInsertId() (gotcha noto).
            $id = (int) $pdo->lastInsertId();
            // Contatore denormalizzato (badge nav): +1 non-letto al destinatario (l'altro partecipante).
            $pdo->prepare(
                'UPDATE profiles SET unread_messages = unread_messages + 1
                 WHERE id = (SELECT CASE WHEN c.profile_a_id = :s THEN c.profile_b_id ELSE c.profile_a_id END
                             FROM conversations c WHERE c.id = :c)'
            )->execute(['s' => $senderId, 'c' => $conversationId]);
            return $id;
        });
    }

    /** Messaggi della conversazione, più recenti prima (il service li rovescia per la vista). */
    public function thread(int $conversationId, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, sender_id, body, read_at, created_at FROM messages
             WHERE conversation_id = :c ORDER BY id DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':c', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Messaggi della conversazione con id > $afterId (polling), cronologici. */
    public function after(int $conversationId, int $afterId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, sender_id, body, read_at, created_at FROM messages
             WHERE conversation_id = :c AND id > :after ORDER BY id ASC LIMIT :lim'
        );
        $stmt->bindValue(':c', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Segna letti i messaggi ricevuti (non inviati da $recipientId) ancora non letti. */
    public function markRead(int $conversationId, int $recipientId): void
    {
        // Atomico: UPDATE read_at + decremento contatore nella STESSA transazione (no drift).
        Db::transaction($this->pdo, function (PDO $pdo) use ($conversationId, $recipientId): void {
            $stmt = $pdo->prepare(
                'UPDATE messages SET read_at = NOW()
                 WHERE conversation_id = :c AND sender_id <> :r AND read_at IS NULL'
            );
            $stmt->execute(['c' => $conversationId, 'r' => $recipientId]);
            // Decrementa il contatore denormalizzato del destinatario dei messaggi appena letti.
            $n = $stmt->rowCount();
            if ($n > 0) {
                $pdo->prepare(
                    'UPDATE profiles SET unread_messages = GREATEST(0, unread_messages - :n) WHERE id = :r'
                )->execute(['n' => $n, 'r' => $recipientId]);
            }
        });
    }

    public function lastMessage(int $conversationId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sender_id, body, created_at FROM messages WHERE conversation_id = :c ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['c' => $conversationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Totale messaggi non letti destinati a $profileId (badge nav). */
    public function unreadTotal(int $profileId): int
    {
        // Badge nav: legge il contatore denormalizzato invece di un COUNT(*) live con JOIN.
        $stmt = $this->pdo->prepare('SELECT unread_messages FROM profiles WHERE id = :id');
        $stmt->execute(['id' => $profileId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<int,int> mappa conversation_id => numero non letti (per l'inbox) */
    public function unreadByConversation(int $profileId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT m.conversation_id, COUNT(*) AS cnt FROM messages m JOIN conversations c ON c.id = m.conversation_id
             WHERE (c.profile_a_id = :me1 OR c.profile_b_id = :me2) AND m.sender_id <> :me3 AND m.read_at IS NULL
             GROUP BY m.conversation_id"
        );
        $stmt->execute(['me1' => $profileId, 'me2' => $profileId, 'me3' => $profileId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['conversation_id']] = (int) $row['cnt'];
        }
        return $map;
    }
}
