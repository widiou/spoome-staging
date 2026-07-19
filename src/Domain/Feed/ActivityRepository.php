<?php

namespace Spoome\Domain\Feed;

use PDO;
use Spoome\Core\Db;

/**
 * Eventi-attività automatici del feed. Il testo utile (`meta`) è denormalizzato al momento
 * della registrazione: il feed resta leggibile anche se l'entità collegata cambia in seguito.
 */
final class ActivityRepository
{
    public const ACHIEVEMENT_ADDED = 'achievement_added';
    public const EXPERIENCE_ADDED  = 'experience_added';
    public const FOLLOWED          = 'followed';
    public const CONNECTED         = 'connected';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    public function record(int $profileId, string $type, ?int $subjectId = null, ?string $meta = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO activities (profile_id, type, subject_id, meta) VALUES (:p, :t, :s, :m)'
        );
        $stmt->execute([
            'p' => $profileId,
            't' => $type,
            's' => $subjectId,
            'm' => $meta !== null ? mb_substr($meta, 0, 255) : null,
        ]);
    }
}
