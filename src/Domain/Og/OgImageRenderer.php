<?php

namespace Spoome\Domain\Og;

/**
 * Renderer GD della card social og:image (1200×630, standard OG per FB/WhatsApp/Telegram).
 *
 * Approccio (deciso per l'hosting condiviso SiteGround, dove GD è GIÀ usato in produzione da
 * {@see \Spoome\Domain\Media\ImageService}, mentre Imagick non è garantito):
 *  - RICH  → GD + FreeType + i font Barlow TTF in `resources/fonts/`. Tipografia on-brand.
 *  - DEGRADE → se manca FreeType o i TTF: card comunque on-brand (colori/anello/badge disegnati),
 *    testo con il font bitmap integrato di GD ingrandito. Mai un'anteprima rotta, solo più sobria.
 *  - FLOOR → se persino GD dovesse fallire: PNG di brand pieno (o costante base64 come ultimissima rete).
 *
 * REGOLA D'ORO (come i nav-helper): questo codice NON solleva MAI. Ogni ramo termina in byte PNG validi.
 * Palette: dark #101218, accento giallo #D8F21D usato SOLO come riempimento (anello/badge/monogramma),
 * mai come colore di testo. Niente verde, niente emoji.
 */
final class OgImageRenderer
{
    private const W = 1200;
    private const H = 630;
    private const PAD = 78; // ~6.5%

    // Palette (token app.css)
    private const C_BG        = [0x10, 0x12, 0x18];
    private const C_SURFACE_2 = [0x1F, 0x23, 0x2B];
    private const C_BORDER    = [0x2E, 0x31, 0x33];
    private const C_TEXT      = [0xED, 0xEF, 0xF2];
    private const C_MUTED     = [0x9B, 0xA1, 0xA9];
    private const C_YELLOW    = [0xD8, 0xF2, 0x1D];

    /** PNG dark 2×2 minimo: rete di sicurezza SOLO se GD è del tutto inutilizzabile (non accade: ImageService lo usa). */
    private const FLOOR_B64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAEklEQVR42mNkYPhfz0AEYBxVSFEG'
        . 'AJvvBPMTNXf3AAAAAElFTkSuQmCC';

    private string $fontBold;   // Barlow Condensed 700 (nome)
    private string $fontSemi;   // Barlow SemiBold 600 (badge, wordmark, iniziali)
    private string $fontMed;    // Barlow Medium 500 (fatti, handle)

    public function __construct()
    {
        $dir = dirname(__DIR__, 3) . '/resources/fonts';
        $this->fontBold = $dir . '/BarlowCondensed-Bold.ttf';
        $this->fontSemi = $dir . '/Barlow-SemiBold.ttf';
        $this->fontMed  = $dir . '/Barlow-Medium.ttf';
    }

    /**
     * Genera i byte PNG della card per il modello di {@see OgCardData::fromProfile}.
     *
     * NB: la degradazione senza font TTF/FreeType NON è un errore — `canvas()` produce comunque una card
     * valida (font bitmap). Un'eccezione qui significa un errore GD "duro" (memoria/estensione): la lascia
     * RISALIRE di proposito, così {@see OgImageService} la distingue da una card valida e serve il brand
     * come RIPIEGO (con `no-store`), senza cacharlo sull'URL versionato.
     *
     * @param array<string,mixed> $card
     * @throws \Throwable su errore GD irrecuperabile
     */
    public function render(array $card): string
    {
        $img = $this->canvas($card);
        return $this->toPng($img);
    }

    /** Card di brand (fallback globale): dark + monogramma giallo + wordmark. Usata quando manca il profilo. */
    public function brandCard(): string
    {
        try {
            $img = $this->baseCanvas();
            $w = self::W;
            // Monogramma giallo centrato + wordmark sotto.
            $box = 150;
            $bx = (int) (($w - $box) / 2);
            $by = 200;
            $this->roundedRect($img, $bx, $by, $box, $box, 34, self::C_YELLOW);
            $this->centerText($img, 'S', $bx, $by, $box, $box, 96, self::C_BG, $this->fontBold);
            $this->centerText($img, 'Spoome', 0, $by + $box + 28, $w, 60, 48, self::C_TEXT, $this->fontSemi);
            $this->centerText($img, 'Il professional network dello sport', 0, $by + $box + 96, $w, 40, 26, self::C_MUTED, $this->fontMed);
            return $this->toPng($img);
        } catch (\Throwable $e) {
            return base64_decode(self::FLOOR_B64) ?: '';
        }
    }

    /** Ultimissima rete: byte PNG sempre validi (costante). */
    public static function floor(): string
    {
        return base64_decode(self::FLOOR_B64) ?: '';
    }

    /* ------------------------------------------------------------------ compose ---- */

    /** @param array<string,mixed> $card */
    private function canvas(array $card): \GdImage
    {
        $img = $this->baseCanvas();

        // Copertina attenuata dietro un doppio gradiente (testo sempre leggibile, con o senza cover).
        $coverPath = $card['cover_path'] ?? null;
        if (is_string($coverPath) && $coverPath !== '') {
            $this->drawCover($img, $coverPath);
        }

        $this->drawBrandMark($img);

        // Blocco contenuto ancorato in basso.
        $avatarD = 190;
        $ax = self::PAD;
        $ay = self::H - self::PAD - $avatarD - 150; // spazio per avatar + tre righe di testo
        $this->drawAvatar($img, $card, $ax, $ay, $avatarD);

        $tx = self::PAD;
        $ty = $ay + $avatarD + 44;

        // Nome (Barlow Condensed 700) + eventuale pillola badge in linea.
        $nameSize = 68;
        $name = $this->fit($card['name'] ?? '', $nameSize, $this->fontBold, self::W - self::PAD * 2);
        $this->text($img, $name, $tx, $ty, $nameSize, self::C_TEXT, $this->fontBold);
        $nameW = $this->textW($name, $nameSize, $this->fontBold);

        if (($card['badge'] ?? 'none') !== 'none' && !empty($card['badge_label'])) {
            $this->drawBadge($img, (string) $card['badge_label'], $tx + $nameW + 26, $ty - $nameSize + 4);
        }

        // Handle + riga fatti (tipo · sport · città).
        $ty += 52;
        $handle = (string) ($card['handle'] ?? '');
        if ($handle !== '') {
            $this->text($img, '@' . $handle, $tx, $ty, 30, self::C_MUTED, $this->fontMed);
            $ty += 44;
        }
        $facts = (string) ($card['facts'] ?? '');
        if ($facts !== '') {
            $facts = $this->fit($facts, 30, $this->fontMed, self::W - self::PAD * 2);
            $this->text($img, $facts, $tx, $ty, 30, self::C_MUTED, $this->fontMed);
        }

        return $img;
    }

    private function baseCanvas(): \GdImage
    {
        $img = $this->newImage(self::W, self::H);
        imagealphablending($img, true);
        imagefilledrectangle($img, 0, 0, self::W, self::H, $this->color($img, self::C_BG));
        return $img;
    }

    /** imagecreatetruecolor con guardia sul false (GD non disponibile/memoria): l'errore risale al fallback. */
    private function newImage(int $w, int $h): \GdImage
    {
        $img = imagecreatetruecolor($w, $h);
        if (!$img instanceof \GdImage) {
            throw new \RuntimeException('gd_unavailable');
        }
        return $img;
    }

    private function drawCover(\GdImage $img, string $path): void
    {
        $src = $this->load($path);
        if ($src === null) {
            return;
        }
        try {
            $sw = imagesx($src);
            $sh = imagesy($src);
            // Cover-fit (center-crop) su tutta la tela.
            $target = self::W / self::H;
            $aspect = $sw / $sh;
            if ($aspect > $target) {
                $cropH = $sh;
                $cropW = (int) round($sh * $target);
            } else {
                $cropW = $sw;
                $cropH = (int) round($sw / $target);
            }
            $sx = (int) (($sw - $cropW) / 2);
            $sy = (int) (($sh - $cropH) / 2);
            imagecopyresampled($img, $src, 0, 0, $sx, $sy, self::W, self::H, $cropW, $cropH);
            $this->scrim($img);
        } finally {
            imagedestroy($src);
        }
    }

    /** Doppio gradiente: dall'alto verso trasparente, dal basso verso il bg. Testo leggibile sempre. */
    private function scrim(\GdImage $img): void
    {
        imagealphablending($img, true);
        [$r, $g, $b] = self::C_BG;
        // Alto: velo leggero.
        $topH = (int) (self::H * 0.42);
        for ($y = 0; $y < $topH; $y++) {
            $a = (int) round(60 * (1 - $y / $topH)); // 0..~47% alpha (0..127)
            $col = imagecolorallocatealpha($img, $r, $g, $b, 127 - $a);
            imageline($img, 0, $y, self::W, $y, $col);
        }
        // Basso: velo forte per il blocco testo.
        $botStart = (int) (self::H * 0.40);
        for ($y = $botStart; $y < self::H; $y++) {
            $t = ($y - $botStart) / (self::H - $botStart);
            $a = (int) round(118 * $t); // fino a ~92% alpha
            $col = imagecolorallocatealpha($img, $r, $g, $b, 127 - $a);
            imageline($img, 0, $y, self::W, $y, $col);
        }
    }

    private function drawBrandMark(\GdImage $img): void
    {
        $box = 52;
        $by = self::PAD - 8;
        $bx = self::W - self::PAD - $box;
        // Wordmark a sinistra del monogramma (blocco allineato a destra).
        $word = 'Spoome';
        $wSize = 30;
        $wW = $this->textW($word, $wSize, $this->fontSemi);
        $this->text($img, $word, $bx - 14 - $wW, $by + 38, $wSize, self::C_TEXT, $this->fontSemi);
        $this->roundedRect($img, $bx, $by, $box, $box, 14, self::C_YELLOW);
        $this->centerText($img, 'S', $bx, $by, $box, $box, 38, self::C_BG, $this->fontBold);
    }

    /** @param array<string,mixed> $card */
    private function drawAvatar(\GdImage $img, array $card, int $x, int $y, int $d): void
    {
        $ring = !empty($card['verified_ring']);
        $isOrg = !empty($card['is_org']);
        $radius = $isOrg ? 28 : (int) ($d / 2);
        $cx = $x + (int) ($d / 2);
        $cy = $y + (int) ($d / 2);

        if ($ring) {
            // Anello giallo (rinforzo visivo del badge testuale, mai unico segnale).
            $rw = 8;
            if ($isOrg) {
                $this->roundedRect($img, $x - $rw, $y - $rw, $d + 2 * $rw, $d + 2 * $rw, $radius + $rw, self::C_YELLOW);
            } else {
                imagefilledellipse($img, $cx, $cy, $d + 2 * $rw, $d + 2 * $rw, $this->color($img, self::C_YELLOW));
            }
        }

        $avatarPath = $card['avatar_path'] ?? null;
        $photo = is_string($avatarPath) && $avatarPath !== '' ? $this->load($avatarPath) : null;

        if ($photo !== null) {
            try {
                $sq = $this->squareResample($photo, $d);
                $this->maskCorners($sq, $d, $radius);
                imagecopy($img, $sq, $x, $y, 0, 0, $d, $d);
                imagedestroy($sq);
                return;
            } catch (\Throwable $e) {
                // cade sulle iniziali
            } finally {
                imagedestroy($photo);
            }
        }

        // Iniziali su surface-2 (stesso pattern del sito; mai un'icona generica).
        if ($isOrg) {
            $this->roundedRect($img, $x, $y, $d, $d, $radius, self::C_SURFACE_2);
        } else {
            imagefilledellipse($img, $cx, $cy, $d, $d, $this->color($img, self::C_SURFACE_2));
        }
        $this->centerText($img, (string) ($card['initials'] ?? '?'), $x, $y, $d, $d, 74, self::C_TEXT, $this->fontSemi);
    }

    private function drawBadge(\GdImage $img, string $label, int $x, int $y): void
    {
        $size = 26;
        $padX = 22;
        $iconR = 14;
        $gap = 12;
        $h = 52;
        $textW = $this->textW($label, $size, $this->fontSemi);
        $w = $padX + $iconR * 2 + $gap + $textW + $padX;

        $this->roundedRect($img, $x, $y, $w, $h, 26, self::C_SURFACE_2);
        $this->roundedRectBorder($img, $x, $y, $w, $h, 26, self::C_BORDER);

        // Icona check: cerchio giallo pieno + spunta scura disegnata (icona+testo, mai solo colore).
        $cx = $x + $padX + $iconR;
        $cy = $y + (int) ($h / 2);
        imagefilledellipse($img, $cx, $cy, $iconR * 2, $iconR * 2, $this->color($img, self::C_YELLOW));
        $this->checkMark($img, $cx, $cy, $iconR, self::C_BG);

        $ty = $y + (int) (($h + $size) / 2) - 4;
        $this->text($img, $label, $cx + $iconR + $gap, $ty, $size, self::C_TEXT, $this->fontSemi);
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private function checkMark(\GdImage $img, int $cx, int $cy, int $r, array $rgb): void
    {
        $col = $this->color($img, $rgb);
        imagesetthickness($img, max(3, (int) ($r / 4)));
        $x1 = $cx - (int) ($r * 0.45); $y1 = $cy + (int) ($r * 0.02);
        $x2 = $cx - (int) ($r * 0.10); $y2 = $cy + (int) ($r * 0.40);
        $x3 = $cx + (int) ($r * 0.50); $y3 = $cy - (int) ($r * 0.42);
        imageline($img, $x1, $y1, $x2, $y2, $col);
        imageline($img, $x2, $y2, $x3, $y3, $col);
        imagesetthickness($img, 1);
    }

    /* ------------------------------------------------------------------ text ---- */

    private function ttf(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $info = function_exists('gd_info') ? gd_info() : [];
            $ok = function_exists('imagettftext')
                && !empty($info['FreeType Support'])
                && is_file($this->fontBold) && is_file($this->fontSemi) && is_file($this->fontMed);
        }
        return $ok;
    }

    /**
     * Disegna testo con baseline a $y. Usa TTF se disponibile, altrimenti il font bitmap ingrandito.
     * @param array{0:int,1:int,2:int} $rgb
     */
    private function text(\GdImage $img, string $s, int $x, int $y, int $size, array $rgb, string $font): void
    {
        if ($s === '') {
            return;
        }
        if ($this->ttf()) {
            imagettftext($img, $size, 0, $x, $y, $this->color($img, $rgb), $font, $s);
            return;
        }
        $this->bitmapText($img, $s, $x, $y - $size, $size, $rgb);
    }

    private function textW(string $s, int $size, string $font): int
    {
        if ($s === '') {
            return 0;
        }
        if ($this->ttf()) {
            $b = imagettfbbox($size, 0, $font, $s);
            if ($b !== false) {
                return (int) abs($b[2] - $b[0]);
            }
        }
        $scale = max(1, (int) round($size / 13));
        return (int) (imagefontwidth(5) * $scale * mb_strlen($s));
    }

    /**
     * Fallback senza FreeType: font 5 di GD scritto su buffer e ingrandito (blocky ma leggibile).
     * @param array{0:int,1:int,2:int} $rgb
     */
    private function bitmapText(\GdImage $img, string $s, int $x, int $top, int $size, array $rgb): void
    {
        $s = $this->ascii($s);
        $fw = imagefontwidth(5);
        $fh = imagefontheight(5);
        $len = max(1, strlen($s));
        $buf = $this->newImage($fw * $len, $fh);
        $bg = $this->color($buf, self::C_BG);
        imagefilledrectangle($buf, 0, 0, imagesx($buf), imagesy($buf), $bg);
        imagecolortransparent($buf, $bg);
        imagestring($buf, 5, 0, 0, $s, $this->color($buf, $rgb));
        $scale = max(1, (int) round($size / $fh));
        imagecopyresized($img, $buf, $x, $top, 0, 0, $fw * $len * $scale, $fh * $scale, $fw * $len, $fh);
        imagedestroy($buf);
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private function centerText(\GdImage $img, string $s, int $x, int $y, int $w, int $h, int $size, array $rgb, string $font): void
    {
        $tw = $this->textW($s, $size, $font);
        $tx = $x + (int) (($w - $tw) / 2);
        if ($this->ttf()) {
            $b = imagettfbbox($size, 0, $font, $s);
            $th = $b !== false ? (int) abs($b[7] - $b[1]) : $size;
            $ty = $y + (int) (($h + $th) / 2);
            imagettftext($img, $size, 0, $tx, $ty, $this->color($img, $rgb), $font, $s);
            return;
        }
        $this->bitmapText($img, $s, $tx, $y + (int) (($h - $size) / 2), $size, $rgb);
    }

    /** Tronca con ellissi se il testo supera la larghezza disponibile. */
    private function fit(string $s, int $size, string $font, int $maxW): string
    {
        if ($this->textW($s, $size, $font) <= $maxW) {
            return $s;
        }
        while ($s !== '' && $this->textW($s . '…', $size, $font) > $maxW) {
            $s = mb_substr($s, 0, mb_strlen($s) - 1);
        }
        return $s . '…';
    }

    private function ascii(string $s): string
    {
        $s = str_replace('…', '...', $s);
        return preg_replace('/[^\x20-\x7E]/', '', $s) ?? $s;
    }

    /* ------------------------------------------------------------------ shapes ---- */

    private function squareResample(\GdImage $src, int $d): \GdImage
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $side = min($sw, $sh);
        $sx = (int) (($sw - $side) / 2);
        $sy = (int) (($sh - $side) / 2);
        $out = $this->newImage($d, $d);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $src, 0, 0, $sx, $sy, $d, $d, $side, $side);
        return $out;
    }

    /** Rende trasparenti gli angoli fuori dal raggio (cerchio se radius=d/2, altrimenti rounded-rect). */
    private function maskCorners(\GdImage $img, int $d, int $radius): void
    {
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        for ($y = 0; $y < $d; $y++) {
            for ($x = 0; $x < $d; $x++) {
                if ($this->outsideRounded($x, $y, $d, $radius)) {
                    imagesetpixel($img, $x, $y, $transparent);
                }
            }
        }
    }

    private function outsideRounded(int $x, int $y, int $d, int $r): bool
    {
        $r = max(0, min($r, (int) ($d / 2)));
        // Regioni d'angolo: controlla la distanza dal centro dell'arco.
        $cx = $x < $r ? $r : ($x > $d - $r ? $d - $r : $x);
        $cy = $y < $r ? $r : ($y > $d - $r ? $d - $r : $y);
        $dx = $x - $cx;
        $dy = $y - $cy;
        return ($dx * $dx + $dy * $dy) > $r * $r;
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private function roundedRect(\GdImage $img, int $x, int $y, int $w, int $h, int $r, array $rgb): void
    {
        $col = $this->color($img, $rgb);
        $r = max(0, min($r, (int) (min($w, $h) / 2)));
        imagefilledrectangle($img, $x + $r, $y, $x + $w - $r, $y + $h, $col);
        imagefilledrectangle($img, $x, $y + $r, $x + $w, $y + $h - $r, $col);
        imagefilledellipse($img, $x + $r, $y + $r, $r * 2, $r * 2, $col);
        imagefilledellipse($img, $x + $w - $r, $y + $r, $r * 2, $r * 2, $col);
        imagefilledellipse($img, $x + $r, $y + $h - $r, $r * 2, $r * 2, $col);
        imagefilledellipse($img, $x + $w - $r, $y + $h - $r, $r * 2, $r * 2, $col);
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private function roundedRectBorder(\GdImage $img, int $x, int $y, int $w, int $h, int $r, array $rgb): void
    {
        $col = $this->color($img, $rgb);
        $r = max(0, min($r, (int) (min($w, $h) / 2)));
        imagesetthickness($img, 2);
        imageline($img, $x + $r, $y, $x + $w - $r, $y, $col);
        imageline($img, $x + $r, $y + $h, $x + $w - $r, $y + $h, $col);
        imageline($img, $x, $y + $r, $x, $y + $h - $r, $col);
        imageline($img, $x + $w, $y + $r, $x + $w, $y + $h - $r, $col);
        imagearc($img, $x + $r, $y + $r, $r * 2, $r * 2, 180, 270, $col);
        imagearc($img, $x + $w - $r, $y + $r, $r * 2, $r * 2, 270, 360, $col);
        imagearc($img, $x + $r, $y + $h - $r, $r * 2, $r * 2, 90, 180, $col);
        imagearc($img, $x + $w - $r, $y + $h - $r, $r * 2, $r * 2, 0, 90, $col);
        imagesetthickness($img, 1);
    }

    /* ------------------------------------------------------------------ io ---- */

    private function load(string $path): ?\GdImage
    {
        if (!is_file($path)) {
            return null;
        }
        $mime = @(new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        $img = match ($mime) {
            'image/webp' => @imagecreatefromwebp($path),
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            default      => false,
        };
        return $img instanceof \GdImage ? $img : null;
    }

    private function toPng(\GdImage $img): string
    {
        ob_start();
        imagepng($img, null, 6);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        return $bytes;
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private function color(\GdImage $img, array $rgb): int
    {
        // imagecolorallocate è tipizzato int|false; su una tela truecolor non fallisce mai → cast a int.
        return (int) imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    }
}
