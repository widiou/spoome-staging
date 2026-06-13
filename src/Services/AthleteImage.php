<?php

namespace Spoome\Services;

use GuzzleHttp\Client;
use PDO;
use Spoome\Core\Logger;

/**
 * Download + conversione WebP + salvataggio su disco della foto profilo atleta,
 * con aggiornamento del path in DB. Estratto da Athlete::savePhotoToServer/updatePhotoPath.
 */
final class AthleteImage
{
    private const MAX_WIDTH = 800;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? \Database::getInstance()->getConnection();
    }

    public function store(string $photoUrl, int $id): void
    {
        $subDir        = \substr((string) $id, 0, 2);
        $relativeDir   = \SUB_ROOT . "/assets/profile/$subDir/$id";
        $directoryPath = $_SERVER['DOCUMENT_ROOT'] . $relativeDir;
        $photoPath     = "$directoryPath/$id.webp";
        $relativePhoto = "$relativeDir/$id.webp";

        if (!\is_dir($directoryPath) && !\mkdir($directoryPath, 0755, true) && !\is_dir($directoryPath)) {
            Logger::error('Impossibile creare directory foto', ['dir' => $directoryPath]);
            return;
        }

        try {
            $response = (new Client())->get($photoUrl, [
                'timeout'         => 5,
                'connect_timeout' => 5,
                // Wikimedia (upload.wikimedia.org) richiede uno User-Agent descrittivo.
                'headers'         => ['User-Agent' => 'Spoome/1.0 (https://spoome.it; info@spoome.it)'],
            ]);
            if ($response->getStatusCode() !== 200) {
                return;
            }
            $imageContent = $response->getBody()->getContents();
        } catch (\Throwable $e) {
            return;
        }

        $source = @\imagecreatefromstring($imageContent);
        if ($source === false) {
            Logger::error('GD: immagine non valida', ['url' => $photoUrl]);
            return;
        }

        $width     = \imagesx($source);
        $height    = \imagesy($source);
        $newWidth  = ($width > self::MAX_WIDTH) ? self::MAX_WIDTH : $width;
        $newHeight = (int) (($height / $width) * $newWidth);

        $resized = \imagecreatetruecolor($newWidth, $newHeight);
        \imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        \imagedestroy($source);

        if (!\imagewebp($resized, $photoPath, 80)) {
            Logger::error('Salvataggio WebP fallito', ['path' => $photoPath]);
        }
        \imagedestroy($resized);

        if (\file_exists($photoPath)) {
            $this->updatePath($relativePhoto, $id);
        } else {
            Logger::error('WebP non creato', ['path' => $photoPath]);
        }
    }

    private function updatePath(string $photoPath, int $id): void
    {
        $photoPath = \str_replace(\SUB_ROOT, '', $photoPath);
        $stmt = $this->pdo->prepare('UPDATE athletes SET photo = :photoPath WHERE id = :id');
        if (!$stmt->execute(['photoPath' => $photoPath, 'id' => $id])) {
            Logger::error('Update photo path fallito', ['id' => $id]);
            throw new \RuntimeException('Update photo path fallito per atleta ' . $id);
        }
    }
}
