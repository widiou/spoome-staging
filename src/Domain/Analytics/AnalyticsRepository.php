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
     * Serie temporale giornaliera di UN tipo di evento dal giorno $sinceDate ('Y-m-d') a oggi (giorni
     * a zero NON riempiti qui: lo normalizza il chiamante). Usa idx_ae_type_created. :t e :since una
     * sola volta.
     *
     * Il confine della finestra ($sinceDate) è calcolato dal chiamante con lo STESSO orologio PHP
     * (Europe/Rome) che genera l'asse delle etichette → finestra ed etichette provengono dallo stesso
     * clock, niente off-by-one né disallineamento del bordo (a differenza di CURDATE()/NOW() lato DB).
     * TODO(tz, progetto): DATE(created_at) bucketizza ancora nella session-tz MySQL; l'allineamento
     * pieno richiede di fissare @@session.time_zone in Core\Db (cambio cross-cutting da regressione
     * su TUTTI i moduli, es. AdminStatsService) — fuori dallo scope di questo worktree.
     *
     * @return array<string,int> 'Y-m-d' => count
     */
    public function dailySeries(string $eventType, string $sinceDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DATE(created_at) AS d, COUNT(*) AS c
             FROM analytics_events
             WHERE event_type = :t AND created_at >= :since
             GROUP BY d
             ORDER BY d'
        );
        $stmt->bindValue(':t', $eventType, PDO::PARAM_STR);
        $stmt->bindValue(':since', $sinceDate, PDO::PARAM_STR);
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
     * Conversione con vero SEMI-JOIN: `from` = attori distinti che hanno fatto l'evento sorgente nella
     * finestra; `to` = quanti DI QUESTI hanno POI fatto l'evento obiettivo (created_at >= quello della
     * sorgente). Così `to ⊆ from` per costruzione → il rate è sempre ≤ 100% (fix del difetto per cui,
     * con profile_open su OGNI visita, due popolazioni indipendenti davano rate > 100%).
     *
     * Gotcha EMULATE_PREPARES=false: la finestra e l'evento sorgente ricorrono in più punti → tutti i
     * placeholder sono SDOPPIATI e mai riusati (: from1/2/3, :ef1/2).
     *
     * @return array{from:int,to:int}
     */
    public function conversion(string $fromEvent, string $toEvent, int $days): array
    {
        $days = max(1, $days);
        $stmt = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(DISTINCT actor_user_id) FROM analytics_events
                   WHERE event_type = :ef1 AND actor_user_id IS NOT NULL
                     AND created_at >= (NOW() - INTERVAL :from1 DAY)) AS from_actors,
                (SELECT COUNT(DISTINCT s.actor_user_id) FROM analytics_events s
                   WHERE s.event_type = :ef2 AND s.actor_user_id IS NOT NULL
                     AND s.created_at >= (NOW() - INTERVAL :from2 DAY)
                     AND EXISTS (
                         SELECT 1 FROM analytics_events o
                          WHERE o.actor_user_id = s.actor_user_id
                            AND o.event_type = :et
                            AND o.created_at >= s.created_at
                            AND o.created_at >= (NOW() - INTERVAL :from3 DAY)
                     )) AS to_actors'
        );
        $stmt->bindValue(':ef1', $fromEvent, PDO::PARAM_STR);
        $stmt->bindValue(':ef2', $fromEvent, PDO::PARAM_STR);
        $stmt->bindValue(':et', $toEvent, PDO::PARAM_STR);
        $stmt->bindValue(':from1', $days, PDO::PARAM_INT);
        $stmt->bindValue(':from2', $days, PDO::PARAM_INT);
        $stmt->bindValue(':from3', $days, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch() ?: ['from_actors' => 0, 'to_actors' => 0];
        return ['from' => (int) $row['from_actors'], 'to' => (int) $row['to_actors']];
    }

    /* =========================================================== RETENTION ==== */

    /**
     * Potatura a batch SINGOLO (una sola DELETE ... LIMIT, nessun loop): lavoro e latenza limitati,
     * pensata per l'innesco inline probabilistico (vedi AnalyticsService::maybePrune). Ritorna le
     * righe rimosse in questo colpo. Range-scan su idx_ae_created. :d e :lim una sola volta.
     */
    public function pruneOnce(int $days, int $batch = 1000): int
    {
        $days  = max(1, $days);
        $batch = max(1, min($batch, 50000));
        $stmt = $this->pdo->prepare(
            'DELETE FROM analytics_events WHERE created_at < (NOW() - INTERVAL :d DAY) LIMIT :lim'
        );
        $stmt->bindValue(':d', $days, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $batch, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Potatura COMPLETA on-demand (NO cron): cancella a BATCH in loop tutti gli eventi oltre la
     * finestra di retention, per non tenere lock lunghi. Per un'azione admin esplicita (drena tutto
     * l'arretrato in un colpo). Range-scan su idx_ae_created. :d e :lim una sola volta per iterazione.
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
