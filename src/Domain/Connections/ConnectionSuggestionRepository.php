<?php

namespace Spoome\Domain\Connections;

use PDO;
use Spoome\Core\Db;

/**
 * Scoperta "Persone che potresti conoscere" (2° grado) + fallback cold-start.
 *
 * Nota PDO (EMULATE_PREPARES=false): un named placeholder NON è riusabile nella
 * stessa query → l'id del profilo corrente compare con placeholder DISTINTI
 * (:me1..:meN). Mai bind di placeholder non referenziati (HY093).
 *
 * Perf 2° grado (MySQL 8): la CTE `myfriends` è referenziata due volte e joinata
 * per uguaglianza a `connections.requester_id`/`addressee_id`, così gli indici
 * idx_conn_requester / idx_conn_addressee si attivano invece di materializzare
 * l'intera tabella (regola d'oro anti-full-scan della spec).
 */
final class ConnectionSuggestionRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /** Colonne arricchite (allineate a ProfileRepository::SELECT_ENRICHED). */
    private const ENRICHED_COLS =
        'p.id, p.user_id, p.claim_status, p.profile_type_id, p.handle, p.display_name, p.headline, p.bio,
         p.sport_id, p.avatar_media_id, p.cover_media_id, p.location_city, p.location_region, p.location_country,
         p.verified_at, p.visibility, p.created_at,
         s.name AS sport_name, s.slug AS sport_slug,
         pt.`key` AS type_key, pt.label AS type_label, pt.is_organization,
         am.disk_path AS avatar_path, ac.disk_path AS cover_path';

    private const ENRICHED_JOINS =
        'JOIN profile_types pt ON pt.id = p.profile_type_id
         LEFT JOIN sports s ON s.id = p.sport_id
         LEFT JOIN media am ON am.id = p.avatar_media_id
         LEFT JOIN media ac ON ac.id = p.cover_media_id';

    /**
     * Suggerimenti di 2° grado (amici-di-amici) con conteggio connessioni in comune.
     * @return array<int,array> righe arricchite + chiave 'mutual_count'
     */
    public function secondDegree(int $profileId, ?int $sportId, ?string $city, int $limit = 12): array
    {
        $sql =
            'WITH myfriends AS (
                SELECT addressee_id AS fid FROM connections WHERE requester_id = :me1 AND status = \'accepted\'
                UNION
                SELECT requester_id AS fid FROM connections WHERE addressee_id = :me2 AND status = \'accepted\'
             )
             SELECT ' . self::ENRICHED_COLS . ', foaf.mutual_count
             FROM (
                 SELECT cand_id, COUNT(DISTINCT via) AS mutual_count
                 FROM (
                     SELECT c.addressee_id AS cand_id, c.requester_id AS via
                     FROM connections c
                     JOIN myfriends mf ON mf.fid = c.requester_id
                     WHERE c.status = \'accepted\'
                     UNION ALL
                     SELECT c.requester_id AS cand_id, c.addressee_id AS via
                     FROM connections c
                     JOIN myfriends mf ON mf.fid = c.addressee_id
                     WHERE c.status = \'accepted\'
                 ) foaf_edges
                 GROUP BY cand_id
             ) foaf
             JOIN profiles p ON p.id = foaf.cand_id
             ' . self::ENRICHED_JOINS . '
             WHERE p.visibility = \'public\'
               AND foaf.cand_id <> :me3
               AND foaf.cand_id NOT IN (
                     SELECT addressee_id FROM connections WHERE requester_id = :me4
                     UNION
                     SELECT requester_id FROM connections WHERE addressee_id = :me5
               )
               AND foaf.cand_id NOT IN (
                     SELECT dismissed_profile_id FROM connection_dismissals WHERE profile_id = :me6
               )
             ORDER BY foaf.mutual_count DESC, (p.sport_id = :sport) DESC, p.connections_count DESC, p.id DESC
             LIMIT :lim';

        $stmt = $this->pdo->prepare($sql);
        for ($i = 1; $i <= 6; $i++) {
            $stmt->bindValue(':me' . $i, $profileId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':sport', $sportId, $sportId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Fallback cold-start (grafo rado): stesso sport o stessa città, esclusi già-suggeriti,
     * già-in-relazione e ignorati. WHERE/ORDER costruiti dinamicamente per non bindare mai
     * placeholder non referenziati.
     * @param int[] $excludeIds
     * @return array<int,array>
     */
    public function fallbackBySportOrCity(int $profileId, ?int $sportId, ?string $city, array $excludeIds, int $limit): array
    {
        $where  = ["p.visibility = 'public'", 'p.id <> :me1'];
        $params = [':me1' => [$profileId, PDO::PARAM_INT]];

        // Ramo affinità sport/città (omesso se entrambi NULL → fallback "più connessi").
        if ($sportId !== null && $city !== null && $city !== '') {
            $where[] = '(p.sport_id = :sport OR p.location_city = :city)';
            $params[':sport'] = [$sportId, PDO::PARAM_INT];
            $params[':city']  = [$city, PDO::PARAM_STR];
        } elseif ($sportId !== null) {
            $where[] = 'p.sport_id = :sport';
            $params[':sport'] = [$sportId, PDO::PARAM_INT];
        } elseif ($city !== null && $city !== '') {
            $where[] = 'p.location_city = :city';
            $params[':city'] = [$city, PDO::PARAM_STR];
        }

        $where[] = 'p.id NOT IN (SELECT addressee_id FROM connections WHERE requester_id = :me2
                                 UNION SELECT requester_id FROM connections WHERE addressee_id = :me3)';
        $params[':me2'] = [$profileId, PDO::PARAM_INT];
        $params[':me3'] = [$profileId, PDO::PARAM_INT];

        $where[] = 'p.id NOT IN (SELECT dismissed_profile_id FROM connection_dismissals WHERE profile_id = :me4)';
        $params[':me4'] = [$profileId, PDO::PARAM_INT];

        $excludeIds = array_values(array_unique(array_map('intval', array_filter($excludeIds))));
        if ($excludeIds !== []) {
            $ph = [];
            foreach ($excludeIds as $k => $id) {
                $name = ':ex' . $k;
                $ph[] = $name;
                $params[$name] = [$id, PDO::PARAM_INT];
            }
            $where[] = 'p.id NOT IN (' . implode(',', $ph) . ')';
        }

        // ORDER BY: termini affinità solo se referenziati (placeholder distinti da quelli in WHERE).
        $order = [];
        if ($sportId !== null) {
            $order[] = '(p.sport_id = :sport2) DESC';
            $params[':sport2'] = [$sportId, PDO::PARAM_INT];
        }
        if ($city !== null && $city !== '') {
            $order[] = '(p.location_city = :city2) DESC';
            $params[':city2'] = [$city, PDO::PARAM_STR];
        }
        $order[] = 'p.connections_count DESC';
        $order[] = 'p.id DESC';

        $sql = 'SELECT ' . self::ENRICHED_COLS . '
                FROM profiles p ' . self::ENRICHED_JOINS . '
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY ' . implode(', ', $order) . '
                LIMIT :lim';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $name => [$val, $type]) {
            $stmt->bindValue($name, $val, $type);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Registra un "ignora" (anti-doppione via PK composta). */
    public function dismiss(int $profileId, int $dismissedProfileId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO connection_dismissals (profile_id, dismissed_profile_id) VALUES (:p, :d)'
        );
        $stmt->execute(['p' => $profileId, 'd' => $dismissedProfileId]);
    }
}
