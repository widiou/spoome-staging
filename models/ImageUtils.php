<?php

class ImageUtils
{
    /**
     * Salva un'immagine base64 in formato WebP nella cartella indicata.
     *
     * @param string $base64   Immagine in formato base64
     * @param string $prefix   Prefisso del nome file
     * @param int    $userId   ID dell’utente
     * @param string $path     Percorso relativo per salvataggio (es: /uploads/profili/)
     *
     * @return string          Percorso relativo del file salvato (da salvare nel DB)
     */
    public static function saveBase64Image(string $base64, string $prefix, int $userId, string $path = '/uploads/profili/'): string
    {
        $data = explode(',', $base64);
        if (count($data) !== 2) return '';

        $decoded = base64_decode($data[1]);
        $image = imagecreatefromstring($decoded);

        if (!$image) return '';

        $filename = $prefix . $userId . '_' . time() . '.webp';
        $filepath = $_SERVER['DOCUMENT_ROOT'] . $path . $filename;

        if (!is_dir(dirname($filepath))) {
            mkdir(dirname($filepath), 0755, true);
        }

        // Salva come WebP con qualità 80
        imagewebp($image, $filepath, 80);
        imagedestroy($image);

        return ltrim($path, '/') . $filename;
    }
}
