<?php

namespace Spoome\Support;

/**
 * Utility su stringhe: slug/handle, token sicuri.
 */
final class Str
{
    /** Slug URL-safe da una stringa (ASCII, minuscolo, trattini). */
    public static function slug(string $s): string
    {
        $conv = @\iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        $s = \preg_replace('/[^a-zA-Z0-9]+/', '-', $conv !== false ? $conv : $s);
        return \strtolower(\trim((string) $s, '-'));
    }

    /**
     * Handle valido per un profilo: [a-z0-9-], 3-30 char (trattini, stile URL/LinkedIn).
     * Fallback su un handle casuale se troppo corto.
     */
    public static function handle(string $s): string
    {
        // slug() produce già ASCII minuscolo con trattini singoli e senza trattini ai bordi.
        $h = self::slug($s);
        $h = \substr($h, 0, 30);
        $h = \trim($h, '-'); // il taglio a 30 può lasciare un trattino finale
        if (\strlen($h) < 3) {
            $h = 'u' . \bin2hex(\random_bytes(4));
        }
        return $h;
    }

    /**
     * Tronca una stringa a $max caratteri (multibyte-safe). Idempotente sulle stringhe già corte —
     * sostituisce l'idioma ripetuto `mb_substr($s, 0, $max)` (ed il suo `if mb_strlen > $max`).
     */
    public static function clamp(string $s, int $max): string
    {
        return \mb_substr($s, 0, $max);
    }

    /** Token grezzo casuale (hex) crittograficamente sicuro. */
    public static function token(int $bytes = 32): string
    {
        return \bin2hex(\random_bytes($bytes));
    }

    /** Hash a riposo di un token (SHA-256 hex). Il grezzo non va mai salvato. */
    public static function hashToken(string $raw): string
    {
        return \hash('sha256', $raw);
    }
}
