<?php

namespace Spoome\Domain\Links;

use PDO;
use Spoome\Core\Db;

/**
 * Cache delle anteprime link (tabella `link_previews`), chiavata su url_hash = sha256(URL normalizzato),
 * con TTL via expires_at. I campi sono conservati GREZZI (untrusted): l'escaping avviene nella view (e()).
 */
final class LinkPreviewRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /** Ritorna l'anteprima cache-ata SE ancora fresca (expires_at nel futuro), altrimenti null. */
    public function findFresh(string $urlHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM link_previews WHERE url_hash = :h AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1'
        );
        $stmt->execute(['h' => $urlHash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Ritorna l'anteprima per hash senza vincolo di freschezza (per il render del post). */
    public function find(string $urlHash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM link_previews WHERE url_hash = :h LIMIT 1');
        $stmt->execute(['h' => $urlHash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Carica in batch le anteprime per un insieme di hash (idratazione feed).
     * @param string[] $hashes
     * @return array<string,array> mappa url_hash => riga
     */
    public function findMany(array $hashes): array
    {
        $hashes = array_values(array_unique(array_filter($hashes)));
        if ($hashes === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($hashes), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM link_previews WHERE url_hash IN ($in)");
        foreach ($hashes as $i => $h) {
            $stmt->bindValue($i + 1, $h);
        }
        $stmt->execute();
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['url_hash']] = $row;
        }
        return $map;
    }

    /** Upsert dell'anteprima (idempotente sull'hash). Ttl in secondi → expires_at. */
    public function upsert(array $data, int $ttlSeconds): void
    {
        $sql = 'INSERT INTO link_previews
                    (url_hash, url, type, title, description, image_url, image_proxy_path, site_name,
                     domain, provider, author, embed_url, embed_html, status, fetched_at, expires_at)
                VALUES
                    (:hash, :url, :type, :title, :descr, :img, :proxy, :site,
                     :domain, :provider, :author, :embed_url, :embed_html, :status, NOW(), (NOW() + INTERVAL :ttl SECOND))
                ON DUPLICATE KEY UPDATE
                    url = VALUES(url), type = VALUES(type), title = VALUES(title), description = VALUES(description),
                    image_url = VALUES(image_url), image_proxy_path = VALUES(image_proxy_path),
                    site_name = VALUES(site_name), domain = VALUES(domain), provider = VALUES(provider),
                    author = VALUES(author), embed_url = VALUES(embed_url), embed_html = VALUES(embed_html),
                    status = VALUES(status), fetched_at = NOW(), expires_at = VALUES(expires_at)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':hash', $data['url_hash']);
        $stmt->bindValue(':url', $data['url']);
        $stmt->bindValue(':type', $data['type']);
        $stmt->bindValue(':title', $data['title']);
        $stmt->bindValue(':descr', $data['description']);
        $stmt->bindValue(':img', $data['image_url']);
        $stmt->bindValue(':proxy', $data['image_proxy_path']);
        $stmt->bindValue(':site', $data['site_name']);
        $stmt->bindValue(':domain', $data['domain']);
        $stmt->bindValue(':provider', $data['provider']);
        $stmt->bindValue(':author', $data['author']);
        $stmt->bindValue(':embed_url', $data['embed_url']);
        $stmt->bindValue(':embed_html', $data['embed_html']);
        $stmt->bindValue(':status', $data['status']);
        $stmt->bindValue(':ttl', (int) $ttlSeconds, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Pulizia (job di manutenzione): elimina le anteprime cache-ate ormai scadute (expires_at nel passato).
     * Le righe con expires_at NULL (cache permanente) NON vengono toccate. Batch per non tenere lock lunghi.
     * @return int righe eliminate
     */
    public function purgeExpired(int $batch = 5000): int
    {
        $batch = max(1, min($batch, 50000));
        $total = 0;
        do {
            $stmt = $this->pdo->prepare(
                'DELETE FROM link_previews WHERE expires_at IS NOT NULL AND expires_at < NOW() LIMIT :lim'
            );
            $stmt->bindValue(':lim', $batch, PDO::PARAM_INT);
            $stmt->execute();
            $n = $stmt->rowCount();
            $total += $n;
        } while ($n === $batch);
        return $total;
    }
}
