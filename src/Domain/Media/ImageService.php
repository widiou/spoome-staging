<?php

namespace Spoome\Domain\Media;

use InvalidArgumentException;

/**
 * Elaborazione sicura delle immagini con GD. Sicurezza livello MASSIMO:
 * - il MIME è verificato dal CONTENUTO (finfo), non dall'estensione o dall'header del client;
 * - protezione contro le "decompression bomb" (limite di megapixel);
 * - l'immagine viene sempre RI-CODIFICATA (decodifica + ridisegno), così qualunque payload
 *   nascosto (EXIF, polyglot, script) viene scartato: in output c'è solo pixel data pulito.
 * Avatar = quadrato 512×512; cover = 1500×500 (3:1). Entrambi center-crop e output WebP.
 */
final class ImageService
{
    private const MAX_BYTES     = 8 * 1024 * 1024;  // 8 MB
    // Il client invia sempre un ritaglio già ridotto (≤0.75MP). Il limite copre l'upload diretto via API
    // di una foto "reale" (fino a ~12MP) bloccando le decompression bomb (12MP ≈ 48MB in GD).
    private const MAX_MEGAPIXEL = 12 * 1000 * 1000;
    private const ALLOWED       = ['image/jpeg', 'image/png', 'image/webp'];

    private const AVATAR = [512, 512];
    private const COVER  = [1500, 500];

    /** @return array{width:int,height:int,mime:string,size:int} */
    public function processAvatar(string $srcPath, string $destPath): array
    {
        return $this->process($srcPath, $destPath, self::AVATAR[0], self::AVATAR[1], true);
    }

    /** @return array{width:int,height:int,mime:string,size:int} */
    public function processCover(string $srcPath, string $destPath): array
    {
        return $this->process($srcPath, $destPath, self::COVER[0], self::COVER[1], false);
    }

    /**
     * Valida, ri-codifica e salva. @throws InvalidArgumentException se non è un'immagine valida/ammessa.
     */
    private function process(string $srcPath, string $destPath, int $outW, int $outH, bool $keepAlpha): array
    {
        if (!is_file($srcPath) || filesize($srcPath) === 0) {
            throw new InvalidArgumentException('empty');
        }
        if (filesize($srcPath) > self::MAX_BYTES) {
            throw new InvalidArgumentException('too_large');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($srcPath) ?: '';
        if (!in_array($mime, self::ALLOWED, true)) {
            throw new InvalidArgumentException('bad_type');
        }

        $info = @getimagesize($srcPath);
        if ($info === false) {
            throw new InvalidArgumentException('not_image');
        }
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || ($w * $h) > self::MAX_MEGAPIXEL) {
            throw new InvalidArgumentException('bad_dimensions');
        }

        $src = $this->decode($srcPath, $mime);
        if ($src === null) {
            throw new InvalidArgumentException('decode_failed');
        }

        // Center-crop al rapporto d'aspetto di output, poi ridimensiona (difensivo: il crop è già lato client).
        $targetAspect = $outW / $outH;
        $srcAspect    = $w / $h;
        if ($srcAspect > $targetAspect) {
            $cropH = $h;
            $cropW = (int) round($h * $targetAspect);
        } else {
            $cropW = $w;
            $cropH = (int) round($w / $targetAspect);
        }
        $sx = (int) (($w - $cropW) / 2);
        $sy = (int) (($h - $cropH) / 2);

        $out = imagecreatetruecolor($outW, $outH);
        if ($keepAlpha) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
        }
        imagecopyresampled($out, $src, 0, 0, $sx, $sy, $outW, $outH, $cropW, $cropH);
        imagedestroy($src);

        $dir = dirname($destPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            imagedestroy($out);
            throw new InvalidArgumentException('mkdir_failed');
        }

        $ok = imagewebp($out, $destPath, 82);
        imagedestroy($out);
        if (!$ok) {
            throw new InvalidArgumentException('encode_failed');
        }
        @chmod($destPath, 0644);

        return [
            'width'  => $outW,
            'height' => $outH,
            'mime'   => 'image/webp',
            'size'   => (int) filesize($destPath),
        ];
    }

    private function decode(string $path, string $mime): ?\GdImage
    {
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default      => false,
        };
        return $img instanceof \GdImage ? $img : null;
    }
}
