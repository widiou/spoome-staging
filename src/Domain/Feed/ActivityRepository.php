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

    /**
     * Pota le attività oltre la finestra di retention. Tabella append-only che cresce illimitata:
     * la potatura tiene sotto controllo storage e dimensione indice (coerente con user_events).
     * DELETE a BATCH (LIMIT in loop) per non tenere lock lunghi; servito da idx_act_created
     * (created_at) — vedi migrazione 0033. Idempotente e sicuro a ri-esecuzione.
     * @return int righe eliminate
     */
    public function purgeOlderThan(int $days = 365, int $batch = 5000): int
    {
        $days  = max(1, $days);
        $batch = max(1, min($batch, 50000));
        $total = 0;
        do {
            $stmt = $this->pdo->prepare(
                'DELETE FROM activities WHERE created_at < (NOW() - INTERVAL :d DAY) LIMIT :lim'
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
