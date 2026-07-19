<?php

namespace Spoome\Domain\Follows;

use PDO;
use Spoome\Core\Db;

/**
 * Accesso al grafo `follows` (profilo→profilo). Coppia unica: le scritture sono idempotenti.
 */
final class FollowRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /** Crea la relazione se non esiste. True se creata ORA (false se già presente). */
    public function follow(int $followerId, int $followeeId): bool
    {
        // Atomico: riga sorgente + contatori denormalizzati nella STESSA transazione (no drift sotto crash/concorrenza).
        $created = Db::transaction($this->pdo, function (PDO $pdo) use ($followerId, $followeeId): bool {
            $stmt = $pdo->prepare('INSERT IGNORE INTO follows (follower_id, followee_id) VALUES (:f, :t)');
            $stmt->execute(['f' => $followerId, 't' => $followeeId]);
            if ($stmt->rowCount() !== 1) {
                return false; // già seguito: nessun cambio ai contatori
            }
            // Contatori denormalizzati: +1 follower al target, +1 seguito all'attore.
            $pdo->prepare('UPDATE profiles SET followers_count = followers_count + 1 WHERE id = :t')->execute(['t' => $followeeId]);
            $pdo->prepare('UPDATE profiles SET following_count = following_count + 1 WHERE id = :f')->execute(['f' => $followerId]);
            return true;
        });
        // Il set-sorgente del feed dell'attore include ora un nuovo followee → invalida la sua cache (fuori dalla tx, solo se cambiato).
        if ($created) {
            \Spoome\Domain\Feed\FeedRepository::forgetSources($followerId);
        }
        return $created;
    }

    public function unfollow(int $followerId, int $followeeId): void
    {
        // Atomico: DELETE + decrementi nella STESSA transazione.
        $removed = Db::transaction($this->pdo, function (PDO $pdo) use ($followerId, $followeeId): bool {
            $stmt = $pdo->prepare('DELETE FROM follows WHERE follower_id = :f AND followee_id = :t');
            $stmt->execute(['f' => $followerId, 't' => $followeeId]);
            if ($stmt->rowCount() > 0) {
                $pdo->prepare('UPDATE profiles SET followers_count = GREATEST(0, followers_count - 1) WHERE id = :t')->execute(['t' => $followeeId]);
                $pdo->prepare('UPDATE profiles SET following_count = GREATEST(0, following_count - 1) WHERE id = :f')->execute(['f' => $followerId]);
                return true;
            }
            return false;
        });
        // Il followee esce dal set-sorgente del feed dell'attore → invalida la sua cache.
        if ($removed) {
            \Spoome\Domain\Feed\FeedRepository::forgetSources($followerId);
        }
    }

    public function isFollowing(int $followerId, int $followeeId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM follows WHERE follower_id = :f AND followee_id = :t LIMIT 1');
        $stmt->execute(['f' => $followerId, 't' => $followeeId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Quanti seguono $profileId (contatore denormalizzato). */
    public function followerCount(int $profileId): int
    {
        $stmt = $this->pdo->prepare('SELECT followers_count FROM profiles WHERE id = :p');
        $stmt->execute(['p' => $profileId]);
        return (int) $stmt->fetchColumn();
    }

    /** Quanti profili segue $profileId (contatore denormalizzato). */
    public function followingCount(int $profileId): int
    {
        $stmt = $this->pdo->prepare('SELECT following_count FROM profiles WHERE id = :p');
        $stmt->execute(['p' => $profileId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Sottoinsieme di $followeeIds effettivamente seguiti da $followerId (per marcare le liste).
     * @param int[] $followeeIds
     * @return array<int,bool> mappa followeeId => true
     */
    public function followingMap(int $followerId, array $followeeIds): array
    {
        $followeeIds = array_values(array_unique(array_filter(array_map('intval', $followeeIds))));
        if ($followeeIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($followeeIds), '?'));
        $stmt = $this->pdo->prepare("SELECT followee_id FROM follows WHERE follower_id = ? AND followee_id IN ($in)");
        $stmt->execute(array_merge([$followerId], $followeeIds));
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $map[(int) $id] = true;
        }
        return $map;
    }
}
