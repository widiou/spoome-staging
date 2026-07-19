<?php

namespace Spoome\Domain\Media;

/**
 * Entità Media: un file caricato da un utente (avatar, cover, ecc.).
 */
final class Media
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $kind,
        public readonly string $diskPath,
        public readonly string $mime,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly int $sizeBytes = 0,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id:        (int) $row['id'],
            userId:    (int) $row['user_id'],
            kind:      (string) $row['kind'],
            diskPath:  (string) $row['disk_path'],
            mime:      (string) $row['mime'],
            width:     isset($row['width']) ? (int) $row['width'] : null,
            height:    isset($row['height']) ? (int) $row['height'] : null,
            sizeBytes: (int) ($row['size_bytes'] ?? 0),
        );
    }
}
