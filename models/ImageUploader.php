<?php
class ImageUploader
{
    public static function saveBase64Image(string $base64, string $prefix, int $userId, string $path = '/uploads/profili/', float $aspectRatio = 1.0, int $maxWidth = 800, int $maxHeight = 800): string
    {
        $data = explode(',', $base64);
        if (count($data) !== 2) return '';

        $decoded = base64_decode($data[1]);
        $sourceImage = imagecreatefromstring($decoded);
        if (!$sourceImage) return '';

        $originalWidth = imagesx($sourceImage);
        $originalHeight = imagesy($sourceImage);

        // Calcolo crop centrale per mantenere l'aspect ratio
        $originalRatio = $originalWidth / $originalHeight;
        if ($originalRatio > $aspectRatio) {
            // Immagine più larga del target: crop ai lati
            $newWidth = (int)($originalHeight * $aspectRatio);
            $newHeight = $originalHeight;
            $srcX = (int)(($originalWidth - $newWidth) / 2);
            $srcY = 0;
        } else {
            // Immagine più alta del target: crop in alto e in basso
            $newWidth = $originalWidth;
            $newHeight = (int)($originalWidth / $aspectRatio);
            $srcX = 0;
            $srcY = (int)(($originalHeight - $newHeight) / 2);
        }

        // Crea immagine croppata
        $croppedImage = imagecrop($sourceImage, [
            'x' => $srcX,
            'y' => $srcY,
            'width' => $newWidth,
            'height' => $newHeight
        ]);

        imagedestroy($sourceImage);
        if (!$croppedImage) return '';

        // Resize su destinazione finale
        $finalImage = imagecreatetruecolor($maxWidth, $maxHeight);
        imagecopyresampled($finalImage, $croppedImage, 0, 0, 0, 0, $maxWidth, $maxHeight, $newWidth, $newHeight);
        imagedestroy($croppedImage);

        // Prepara nome file e path
        $filename = $prefix . $userId . '_' . time() . '.webp';
        $filepath = $_SERVER['DOCUMENT_ROOT'] . $path . $filename;

        if (!is_dir(dirname($filepath))) {
            mkdir(dirname($filepath), 0755, true);
        }

        // Salva l'immagine come WebP
        imagewebp($finalImage, $filepath, 80);
        imagedestroy($finalImage);

        return ltrim($path, '/') . $filename;
    }

    public static function saveAndOptimizeImage(string $base64, string $prefix, int $userId, int $maxWidth, int $maxHeight, float $aspectRatio = 1.0, string $path = '/uploads/profili/'): string
    {
        $data = explode(',', $base64);
        if (count($data) !== 2) return '';

        $decoded = base64_decode($data[1]);
        $source = imagecreatefromstring($decoded);

        if (!$source) return '';

        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);

        // Calcola crop centrale mantenendo il rapporto desiderato
        $srcRatio = $srcWidth / $srcHeight;

        if ($srcRatio > $aspectRatio) {
            // Immagine più larga → taglia orizzontalmente
            $newWidth = (int)($srcHeight * $aspectRatio);
            $newHeight = $srcHeight;
            $srcX = (int)(($srcWidth - $newWidth) / 2);
            $srcY = 0;
        } else {
            // Immagine più alta → taglia verticalmente
            $newWidth = $srcWidth;
            $newHeight = (int)($srcWidth / $aspectRatio);
            $srcX = 0;
            $srcY = (int)(($srcHeight - $newHeight) / 2);
        }

        // Crea canvas di destinazione
        $dest = imagecreatetruecolor($maxWidth, $maxHeight);
        imagecopyresampled($dest, $source, 0, 0, $srcX, $srcY, $maxWidth, $maxHeight, $newWidth, $newHeight);
        imagedestroy($source);

        // Salvataggio su disco
        $filename = $prefix . $userId . '_' . time() . '.webp';
        $fullpath = $_SERVER['DOCUMENT_ROOT'] . $path . $filename;

        if (!is_dir(dirname($fullpath))) {
            mkdir(dirname($fullpath), 0755, true);
        }

        imagewebp($dest, $fullpath, 80);
        imagedestroy($dest);

        return ltrim($path, '/') . $filename;
    }

}
