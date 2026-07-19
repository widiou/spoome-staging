<?php

namespace Spoome\Domain\Events;

use PDO;
use Spoome\Core\Db;

/**
 * Lettura dell'inbox durevole `user_events` per l'endpoint consolidato di stream (Phase 1).
 * Ogni lettura è vincolata a un singolo user_id (scoping per-utente imposto qui, oltre che dall'emit).
 */
final class EventRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /**
     * Eventi dell'utente con id > $cursor, in ordine crescente (una query indicizzata su idx_ue_user).
     * @return array<int,array<string,mixed>>
     */
    public function since(int $userId, int $cursor, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, type, actor_profile_id, payload, created_at
             FROM user_events
             WHERE user_id = :u AND id > :cur
             ORDER BY id ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':cur', $cursor, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Numero di "nuovi post" nel feed dell'utente dopo il cursore: SOLO i post degli autori che
     * l'utente vede realmente (sé + seguiti + connessi). Scoping per-utente OBBLIGATORIO — un COUNT
     * globale trapelerebbe l'attività site-wide e il max id monotòno interno a ogni utente.
     * Usa l'indice su posts(profile_id) → costo O(autori nel feed).
     * @param int[] $profileIds audience del feed (da FeedRepository::sourceIds)
     */
    public function newPostsCount(array $profileIds, int $sinceId): int
    {
        $profileIds = array_values(array_unique(array_map('intval', $profileIds)));
        if ($profileIds === []) {
            return 0;
        }
        $in   = implode(',', array_fill(0, count($profileIds), '?'));
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM posts WHERE profile_id IN ($in) AND id > ?");
        $i = 1;
        foreach ($profileIds as $v) {
            $stmt->bindValue($i++, $v, PDO::PARAM_INT);
        }
        $stmt->bindValue($i, $sinceId, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Id del post più recente TRA gli autori del feed dell'utente (nuovo cursore feed del client).
     * NON espone il MAX(id) globale della tabella posts — resta vincolato all'audience dell'utente.
     * @param int[] $profileIds audience del feed (da FeedRepository::sourceIds)
     */
    public function feedLatestPostId(array $profileIds): int
    {
        $profileIds = array_values(array_unique(array_map('intval', $profileIds)));
        if ($profileIds === []) {
            return 0;
        }
        $in   = implode(',', array_fill(0, count($profileIds), '?'));
        $stmt = $this->pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM posts WHERE profile_id IN ($in)");
        $i = 1;
        foreach ($profileIds as $v) {
            $stmt->bindValue($i++, $v, PDO::PARAM_INT);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Pulizia (job di manutenzione): elimina gli eventi più vecchi di N giorni dall'inbox durevole.
     * Il client, sincronizzato via cursore, non ha bisogno di storia oltre la finestra di retention.
     * Batch per non tenere lock lunghi su una tabella append-only che cresce illimitata.
     * @return int righe eliminate
     */
    public function purgeOlderThan(int $days = 30, int $batch = 5000): int
    {
        $days  = max(1, $days);
        $batch = max(1, min($batch, 50000));
        $total = 0;
        do {
            $stmt = $this->pdo->prepare(
                'DELETE FROM user_events WHERE created_at < (NOW() - INTERVAL :d DAY) LIMIT :lim'
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
