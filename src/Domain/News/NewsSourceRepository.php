<?php

namespace Spoome\Domain\News;

use PDO;
use Spoome\Core\Db;

/**
 * CRUD delle fonti news (RSS/Atom) per l'area admin + selezione delle fonti "da aggiornare"
 * secondo l'intervallo per-fonte. Ogni fonte può essere attribuita a una pagina org (federazione)
 * oppure essere una fonte terza (org_profile_id NULL), e tagga uno o più sport per il match interesse.
 */
final class NewsSourceRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /** Elenco fonti per l'admin: con nome org (se attribuita) e conteggio item + sport. */
    public function all(): array
    {
        $rows = $this->pdo->query(
            "SELECT ns.*, p.display_name AS org_name, p.handle AS org_handle,
                    (SELECT COUNT(*) FROM news_items ni WHERE ni.source_id = ns.id) AS item_count
             FROM news_sources ns
             LEFT JOIN profiles p ON p.id = ns.org_profile_id
             ORDER BY ns.active DESC, ns.name ASC"
        )->fetchAll();
        // Sport per fonte (in una query) → mappa.
        $sportMap = [];
        foreach ($this->pdo->query(
            "SELECT nss.source_id, s.id, s.name FROM news_source_sports nss JOIN sports s ON s.id = nss.sport_id ORDER BY s.name"
        )->fetchAll() as $r) {
            $sportMap[(int) $r['source_id']][] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
        }
        foreach ($rows as &$row) {
            $row['sports'] = $sportMap[(int) $row['id']] ?? [];
        }
        return $rows;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM news_sources WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['sports'] = $this->sportIds($id);
        return $row;
    }

    /** @return int[] */
    public function sportIds(int $sourceId): array
    {
        $stmt = $this->pdo->prepare('SELECT sport_id FROM news_source_sports WHERE source_id = :id');
        $stmt->execute(['id' => $sourceId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param int[] $sportIds */
    public function create(?int $orgProfileId, string $name, string $feedUrl, int $refreshMinutes, bool $active, array $sportIds): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO news_sources (org_profile_id, name, feed_url, refresh_minutes, active)
             VALUES (:org, :name, :url, :ref, :act)'
        );
        $stmt->execute([
            'org'  => $orgProfileId,
            'name' => $name,
            'url'  => $feedUrl,
            'ref'  => $refreshMinutes,
            'act'  => $active ? 1 : 0,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->setSports($id, $sportIds);
        return $id;
    }

    /** @param int[] $sportIds */
    public function update(int $id, ?int $orgProfileId, string $name, string $feedUrl, int $refreshMinutes, bool $active, array $sportIds): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE news_sources SET org_profile_id = :org, name = :name, feed_url = :url,
                    refresh_minutes = :ref, active = :act WHERE id = :id'
        );
        $stmt->execute([
            'org'  => $orgProfileId,
            'name' => $name,
            'url'  => $feedUrl,
            'ref'  => $refreshMinutes,
            'act'  => $active ? 1 : 0,
            'id'   => $id,
        ]);
        $this->setSports($id, $sportIds);
    }

    /** Rimpiazza gli sport della fonte. @param int[] $sportIds */
    public function setSports(int $sourceId, array $sportIds): void
    {
        $this->pdo->prepare('DELETE FROM news_source_sports WHERE source_id = :id')->execute(['id' => $sourceId]);
        $ids = array_values(array_unique(array_map('intval', array_filter($sportIds))));
        if ($ids === []) {
            return;
        }
        $ins = $this->pdo->prepare('INSERT IGNORE INTO news_source_sports (source_id, sport_id) VALUES (:s, :sp)');
        foreach ($ids as $sp) {
            $ins->execute(['s' => $sourceId, 'sp' => $sp]);
        }
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM news_sources WHERE id = :id')->execute(['id' => $id]);
    }

    public function toggleActive(int $id): void
    {
        $this->pdo->prepare('UPDATE news_sources SET active = 1 - active WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Fonti attive da aggiornare: mai aggiornate o più vecchie del proprio intervallo.
     * @return array<int,array<string,mixed>>
     */
    public function dueForFetch(): array
    {
        return $this->pdo->query(
            'SELECT * FROM news_sources
             WHERE active = 1
               AND (last_fetched_at IS NULL OR last_fetched_at < (NOW() - INTERVAL refresh_minutes MINUTE))
             ORDER BY last_fetched_at IS NOT NULL, last_fetched_at ASC'
        )->fetchAll();
    }

    public function touchFetched(int $id, ?string $etag, ?string $lastModified): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE news_sources SET last_fetched_at = NOW(), etag = :e, last_modified = :lm WHERE id = :id'
        );
        $stmt->execute(['e' => $etag, 'lm' => $lastModified, 'id' => $id]);
    }
}
