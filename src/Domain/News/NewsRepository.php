<?php

namespace Spoome\Domain\News;

use PDO;
use Spoome\Core\Db;

/**
 * Lettura delle news di settore (articoli RSS di federazioni/organismi) per l'iniezione nel feed.
 * Ogni item è attribuito alla pagina organizzazione della sua fonte (la federazione).
 */
final class NewsRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /**
     * Inserisce un articolo deduplicando per hash dell'URL (INSERT IGNORE). True se nuovo.
     * I campi vengono troncati ai limiti di colonna. Output sempre via e() nella vista.
     */
    public function insertItem(int $sourceId, string $url, string $title, ?string $summary, ?string $imageUrl, ?int $sportId, ?string $publishedAt): bool
    {
        $url   = mb_substr(trim($url), 0, 700);
        $title = mb_substr(trim($title), 0, 300);
        // Difesa in profondità: solo http(s) può finire in un href reso (mai javascript:/data:).
        if ($url === '' || $title === '' || !preg_match('#^https?://#i', $url)) {
            return false;
        }
        if ($imageUrl !== null && !preg_match('#^https?://#i', trim($imageUrl))) {
            $imageUrl = null;
        }
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO news_items (source_id, url_hash, title, url, summary, image_url, sport_id, published_at)
             VALUES (:src, :h, :t, :u, :s, :img, :sp, :pub)'
        );
        $stmt->execute([
            'src' => $sourceId,
            'h'   => hash('sha256', $url),
            't'   => $title,
            'u'   => $url,
            's'   => $summary !== null && $summary !== '' ? mb_substr($summary, 0, 600) : null,
            'img' => $imageUrl !== null && $imageUrl !== '' ? mb_substr($imageUrl, 0, 700) : null,
            'sp'  => $sportId,
            'pub' => $publishedAt,
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * News recenti che matchano gli sport d'interesse dell'utente, attribuite alla pagina fonte.
     * Se $sportIds è vuoto → fallback alle news più recenti di qualsiasi sport (così ognuno vede qualcosa).
     * @param int[] $sportIds
     * @return array<int,array<string,mixed>>
     */
    public function forSports(array $sportIds, int $limit = 3): array
    {
        $limit = max(1, min(20, $limit));
        $ids   = array_values(array_unique(array_map('intval', array_filter($sportIds))));

        // org LEFT JOIN: le fonti terze (org_profile_id NULL) non hanno pagina → attribuzione al nome fonte.
        $sql = "SELECT ni.id, ni.title, ni.url, ni.summary, ni.image_url, ni.published_at, ni.sport_id,
                       s.name AS sport_name,
                       ns.name AS source_name,
                       p.handle AS org_handle, p.display_name AS org_name, p.verified_at AS org_verified,
                       pt.`key` AS org_type_key, pt.is_organization AS org_is_org,
                       am.disk_path AS org_avatar_path
                FROM news_items ni
                JOIN news_sources ns ON ns.id = ni.source_id AND ns.active = 1
                LEFT JOIN profiles p ON p.id = ns.org_profile_id
                LEFT JOIN profile_types pt ON pt.id = p.profile_type_id
                LEFT JOIN sports s ON s.id = ni.sport_id
                LEFT JOIN media am ON am.id = p.avatar_media_id";

        if ($ids !== []) {
            // Match d'interesse sugli sport ASSEGNATI ALLA FONTE (multi-sport) o, in fallback, taggati sull'item.
            $in1 = implode(',', array_fill(0, count($ids), '?'));
            $in2 = implode(',', array_fill(0, count($ids), '?'));
            $sql .= " WHERE (EXISTS (SELECT 1 FROM news_source_sports nss WHERE nss.source_id = ns.id AND nss.sport_id IN ($in1))
                             OR ni.sport_id IN ($in2))";
        }
        $sql .= " ORDER BY ni.published_at DESC, ni.id DESC LIMIT ?";

        $stmt = $this->pdo->prepare($sql);
        $i = 1;
        // EMULATE_PREPARES=false: i placeholder non sono riusabili → lego $ids due volte (EXISTS + item).
        foreach ($ids as $v) {
            $stmt->bindValue($i++, $v, PDO::PARAM_INT);
        }
        foreach ($ids as $v) {
            $stmt->bindValue($i++, $v, PDO::PARAM_INT);
        }
        $stmt->bindValue($i, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
