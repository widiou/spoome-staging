<?php

namespace Spoome\Domain\Profiles;

use PDO;
use Spoome\Core\Db;

/**
 * "Chi ha visto il tuo profilo" (F3). Modello roll-up: UNA riga per coppia (viewer, viewed),
 * upsert su ogni visita → crescita O(coppie distinte), non O(visite). La PK
 * (viewed_profile_id, viewer_profile_id) è insieme chiave dell'upsert e clustering per la
 * query calda del proprietario ("chi ha visto X"). Nessuna notifica: feature passiva, in chiaro.
 */
final class ProfileViewRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /**
     * Registra una visita (upsert). first_viewed_at resta all'insert; last_viewed_at e il
     * contatore si aggiornano sui ritorni. Ogni placeholder compare una sola volta
     * (EMULATE_PREPARES=false: named placeholder non riusabili).
     */
    public function record(int $viewerProfileId, int $viewedProfileId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO profile_views (viewer_profile_id, viewed_profile_id)
             VALUES (:viewer, :viewed)
             ON DUPLICATE KEY UPDATE last_viewed_at = NOW(), view_count = view_count + 1'
        );
        $stmt->execute(['viewer' => $viewerProfileId, 'viewed' => $viewedProfileId]);
    }

    /**
     * Visitatori recenti del profilo (join arricchito + avatar), più recenti prima.
     * Usa idx_pv_viewed_recent. :me compare una sola volta.
     * @return array<int,array> righe enriched + last_viewed_at, view_count
     */
    public function recentViewers(int $profileId, int $limit = 12): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.handle, p.display_name, p.headline, p.verified_at,
                    s.name AS sport_name,
                    pt.`key` AS type_key, pt.label AS type_label, pt.is_organization,
                    am.disk_path AS avatar_path,
                    pv.last_viewed_at, pv.view_count
             FROM profile_views pv
             JOIN profiles p        ON p.id = pv.viewer_profile_id
             JOIN profile_types pt  ON pt.id = p.profile_type_id
             LEFT JOIN sports s     ON s.id = p.sport_id
             LEFT JOIN media am     ON am.id = p.avatar_media_id
             WHERE pv.viewed_profile_id = :me
             ORDER BY pv.last_viewed_at DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':me', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Numero di visitatori DISTINTI negli ultimi 7 giorni (una riga per viewer → distinto implicito). */
    public function distinctViewers7d(int $profileId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM profile_views
             WHERE viewed_profile_id = :me AND last_viewed_at > (NOW() - INTERVAL 7 DAY)'
        );
        $stmt->execute(['me' => $profileId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Trend giornaliero grezzo sugli ultimi 7 giorni: mappa 'Y-m-d' => count.
     * Caveat (modello roll-up): conta i viewer il cui ULTIMO accesso cade in quel giorno,
     * non le visite grezze. Approssimazione voluta. Il chiamante normalizza a 7 bucket.
     * @return array<string,int>
     */
    public function dailyTrend7d(int $profileId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DATE(last_viewed_at) AS d, COUNT(*) AS c
             FROM profile_views
             WHERE viewed_profile_id = :me AND last_viewed_at >= (CURDATE() - INTERVAL 6 DAY)
             GROUP BY d
             ORDER BY d'
        );
        $stmt->execute(['me' => $profileId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['d']] = (int) $row['c'];
        }
        return $out;
    }
}
