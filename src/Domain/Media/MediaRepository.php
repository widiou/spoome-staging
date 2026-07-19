<?php

namespace Spoome\Domain\Media;

use PDO;
use Spoome\Core\Db;

/**
 * Accesso ai dati della tabella `media`.
 */
final class MediaRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    public function create(int $userId, string $kind, string $diskPath, string $mime, int $width, int $height, int $sizeBytes): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO media (user_id, kind, disk_path, mime, width, height, size_bytes)
             VALUES (:uid, :kind, :path, :mime, :w, :h, :size)'
        );
        $stmt->execute([
            'uid'  => $userId,
            'kind' => $kind,
            'path' => $diskPath,
            'mime' => $mime,
            'w'    => $width,
            'h'    => $height,
            'size' => $sizeBytes,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?Media
    {
        $stmt = $this->pdo->prepare('SELECT * FROM media WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? Media::fromRow($row) : null;
    }

    public function deleteById(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM media WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
