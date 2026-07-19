<?php

namespace Spoome\Domain\Messaging;

use PDO;
use Spoome\Core\Db;

/**
 * Conversazioni 1:1. La coppia è canonicalizzata (profile_a_id < profile_b_id) → una sola riga per coppia.
 */
final class ConversationRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /** Coppia ordinata [min, max]. */
    private static function pair(int $x, int $y): array
    {
        return $x < $y ? [$x, $y] : [$y, $x];
    }

    /** Id della conversazione tra x e y, creandola se non esiste. */
    public function findOrCreate(int $x, int $y): int
    {
        [$a, $b] = self::pair($x, $y);
        $this->pdo->prepare('INSERT IGNORE INTO conversations (profile_a_id, profile_b_id) VALUES (:a, :b)')
            ->execute(['a' => $a, 'b' => $b]);
        $stmt = $this->pdo->prepare('SELECT id FROM conversations WHERE profile_a_id = :a AND profile_b_id = :b LIMIT 1');
        $stmt->execute(['a' => $a, 'b' => $b]);
        return (int) $stmt->fetchColumn();
    }

    public function isParticipant(int $convId, int $profileId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM conversations WHERE id = :id AND (profile_a_id = :p1 OR profile_b_id = :p2) LIMIT 1'
        );
        $stmt->execute(['id' => $convId, 'p1' => $profileId, 'p2' => $profileId]);
        return (bool) $stmt->fetchColumn();
    }

    public function touch(int $convId): void
    {
        $this->pdo->prepare('UPDATE conversations SET last_message_at = NOW() WHERE id = :id')->execute(['id' => $convId]);
    }

    /**
     * Conversazioni con almeno un messaggio, più recenti prima. Ogni riga: id, last_message_at, other_id.
     * @return array<int,array>
     */
    public function inbox(int $profileId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, last_message_at,
                    CASE WHEN profile_a_id = :me THEN profile_b_id ELSE profile_a_id END AS other_id
             FROM conversations
             WHERE (profile_a_id = :me1 OR profile_b_id = :me2) AND last_message_at IS NOT NULL
             ORDER BY last_message_at DESC, id DESC"
        );
        $stmt->execute(['me' => $profileId, 'me1' => $profileId, 'me2' => $profileId]);
        return $stmt->fetchAll();
    }
}
