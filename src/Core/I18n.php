<?php

namespace Spoome\Core;

/**
 * Internazionalizzazione: le stringhe UI stanno nei file lang/<locale>.php (array chiave→testo).
 * Uso: I18n::t('auth.login.title') o l'helper globale t(). Interpolazione con {segnaposto}.
 * Aggiungere una lingua = aggiungere lang/en.php con le stesse chiavi.
 */
final class I18n
{
    private static string $locale = 'it';
    private static string $fallback = 'it';
    /** @var array<string,array<string,string>> cache per locale */
    private static array $loaded = [];

    public static function setLocale(string $locale): void
    {
        // Solo lettere/trattino, evita path traversal sul nome file.
        if (preg_match('/^[a-zA-Z_-]{2,10}$/', $locale)) {
            self::$locale = strtolower($locale);
        }
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /**
     * Traduce una chiave. $replace: ['name' => 'Mario'] sostituisce {name}.
     */
    public static function t(string $key, array $replace = []): string
    {
        $text = self::messages(self::$locale)[$key]
            ?? self::messages(self::$fallback)[$key]
            ?? $key;

        foreach ($replace as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }
        return $text;
    }

    /** @return array<string,string> */
    private static function messages(string $locale): array
    {
        if (!isset(self::$loaded[$locale])) {
            $file = \dirname(__DIR__, 2) . '/lang/' . $locale . '.php';
            self::$loaded[$locale] = \is_file($file) ? (array) require $file : [];
        }
        return self::$loaded[$locale];
    }
}
