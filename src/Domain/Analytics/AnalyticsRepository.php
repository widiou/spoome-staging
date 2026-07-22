<?php

namespace Spoome\Domain\Analytics;

use PDO;
use Spoome\Core\Db;

/**
 * Accesso PDO alla tabella `analytics_events` (mig. 0034): un INSERT append-only in scrittura e le
 * aggregazioni on-demand in lettura per l'area admin. Nessuna logica fail-safe qui — la scrittura
 * è avvolta da {@see AnalyticsService} (fire-and-forget), le letture propagano gli errori all'admin.
 *
 * Gotcha PDO (EMULATE_PREPARES=false): i named placeholder NON sono riutilizzabili nella stessa query.
 * Ogni placeholder compare UNA sola volta; dove la stessa finestra serve in due sotto-query la si
 * sdoppia (:from1/:from2). È già stato causa di 500 (HY093).
 */
final class AnalyticsRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /* ============================================================ SCRITTURA ==== */

    /**
     * Registra un evento (INSERT singolo, append-only). Ogni placeholder una sola volta.
     *
     * @param string       $eventType    vocabolario libero (search|profile_open|opportunity_publish|apply|...)
     * @param int|null     $actorUserId  utente autore, NULL = anonimo
     * @param string|null  $subjectType  tipo del target polimorfico ('profile'|'opportunity'|...)
     * @param int|null     $subjectId    id del target
     * @param string|null  $anonId       16 byte binari (hash sessione troncato), o NULL
     * @param array<string,mixed>|null $meta contesto PII-light (mai testo query grezzo)
     */
    public function insert(
        string $eventType,
        ?int $actorUserId,
        ?string $subjectType,
        ?int $subjectId,
        ?string $anonId,
        ?array $meta
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO analytics_events
                (event_type, actor_user_id, subject_type, subject_id, anon_id, meta)
             VALUES (:etype, :actor, :stype, :sid, :anon, :meta)'
        );
        $stmt->bindValue(':etype', $eventType, PDO::PARAM_STR);
        $stmt->bindValue(':actor', $actorUserId, $actorUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':stype', $subjectType, $subjectType === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':sid', $subjectId, $subjectId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        // BINARY(16): 16 byte grezzi come stringa; PARAM_LOB evita interpretazioni di charset.
        if ($anonId === null) {
            $stmt->bindValue(':anon', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':anon', $anonId, PDO::PARAM_LOB);
        }
        $stmt->bindValue(':meta', $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE), $meta === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();
    }

    /* ============================================================== LETTURA ==== */

    /**
     * Funnel: conteggio per tipo di evento nella finestra (giorni). Usa idx_ae_created (range) +
     * aggregazione su event_type. :d una sola volta.
     *
     * @return array<string,int> event_type => count, ordinato per count desc
     */
    public function funnelCounts(int $days): array
    {
        $days = max(1, $days);
        $stmt = $this->pdo->prepare(
            'SELECT event_type, COUNT(*) AS c
             FROM analytics_events
             WHERE created_at >= (NOW() - INTERVAL :d DAY)
             GROUP BY event_type
             ORDER BY c DESC'
        );
        $stmt->bindValue(':d', $days, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['event_type']] = (int) $row['c'];
        }
        return $out;
    }

    /**
     * Serie temporale giornaliera di UN tipo di evento sugli ultimi $days giorni (giorni a zero NON
     * riempiti qui: lo normalizza il chiamante). Usa idx_ae_type_created. :t e :d una sola volta.
     *
     * @return array<string,int> 'Y-m-d' => count
     */
    public function dailySeries(string $eventType, int $days): array
    {
        $days = max(1, $days);
        $stmt = $this->pdo->prepare(
            'SELECT DATE(created_at) AS d, COUNT(*) AS c
             FROM analytics_events
             WHERE event_type = :t AND created_at >= (CURDATE() - INTERVAL :d DAY)
             GROUP BY d
             ORDER BY d'
        );
        $stmt->bindValue(':t', $eventType, PDO::PARAM_STR);
        $stmt->bindValue(':d', $days, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['d']] = (int) $row['c'];
        }
        return $out;
    }

    /**
     * Timeline di un attore: gli ultimi $limit eventi dell'utente, più recenti prima.
     * Usa idx_ae_actor_created. :uid e :lim una sola volta.
     *
     * @return array<int,array{id:int,event_type:string,subject_type:?string,subject_id:?int,meta:?string,created_at:string}>
     */
    public function actorTimeline(int $userId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = $this->pdo->prepare(
            'SELECT id, event_type, subject_type, subject_id, meta, created_at
             FROM analytics_events
             WHERE actor_user_id = :uid
             ORDER BY id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Conversione tra due eventi (attori DISTINTI): quanti attori hanno fatto A e quanti B nella
     * stessa finestra. Dimostra il gotcha EMULATE_PREPARES=false: la finestra serve in DUE
     * sotto-query → placeholder SDOPPIATI (:from1/:from2), mai riusati.
     *
     * @return array{from:int,to:int} conteggi di attori distinti (from = evento sorgente, to = evento obiettivo)
     */
    public function conversion(string $fromEvent, string $toEvent, int $days): array
    {
        $days = max(1, $days);
        $stmt = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(DISTINCT actor_user_id) FROM analytics_events
                   WHERE event_type = :ef AND actor_user_id IS NOT NULL
                     AND created_at >= (NOW() - INTERVAL :from1 DAY)) AS from_actors,
                (SELECT COUNT(DISTINCT actor_user_id) FROM analytics_events
                   WHERE event_type = :et AND actor_user_id IS NOT NULL
                     AND created_at >= (NOW() - INTERVAL :from2 DAY)) AS to_actors'
        );
        $stmt->bindValue(':ef', $fromEvent, PDO::PARAM_STR);
        $stmt->bindValue(':et', $toEvent, PDO::PARAM_STR);
        $stmt->bindValue(':from1', $days, PDO::PARAM_INT);
        $stmt->bindValue(':from2', $days, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch() ?: ['from_actors' => 0, 'to_actors' => 0];
        return ['from' => (int) $row['from_actors'], 'to' => (int) $row['to_actors']];
    }

    /* =========================================================== RETENTION ==== */

    /**
     * Potatura on-demand (NO cron): cancella a BATCH gli eventi oltre la finestra di retention, per
     * non tenere lock lunghi. Range-scan su idx_ae_created. :d e :lim una sola volta per iterazione.
     * Innescabile da un'azione admin o da un prune inline probabilistico. Ritorna le righe rimosse.
     */
    public function pruneOlderThan(int $days, int $batch = 5000): int
    {
        $days  = max(1, $days);
        $batch = max(1, min($batch, 50000));
        $total = 0;
        do {
            $stmt = $this->pdo->prepare(
                'DELETE FROM analytics_events WHERE created_at < (NOW() - INTERVAL :d DAY) LIMIT :lim'
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
