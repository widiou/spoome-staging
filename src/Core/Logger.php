<?php

namespace Spoome\Core;

/**
 * Logger applicativo minimale (PSR-3-like) che scrive su storage/logs/.
 * Sostituisce l'uso di error_log() come logger (causa del php_errorlog gigante).
 * Rotazione semplice per dimensione.
 */
final class Logger
{
    private const MAX_BYTES = 5_242_880; // 5 MB

    private static ?string $dir = null;

    private static function dir(): string
    {
        if (self::$dir === null) {
            // src/Core/Logger.php → root = dirname(__DIR__, 2)
            self::$dir = \dirname(__DIR__, 2) . '/storage/logs';
            if (!\is_dir(self::$dir)) {
                @\mkdir(self::$dir, 0775, true);
            }
        }
        return self::$dir;
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        $file = self::dir() . '/app.log';

        if (\is_file($file) && \filesize($file) > self::MAX_BYTES) {
            @\rename($file, $file . '.' . \date('Ymd_His'));
        }

        $line = \sprintf(
            "[%s] %s: %s%s\n",
            \date('Y-m-d H:i:s'),
            \strtoupper($level),
            $message,
            $context ? ' ' . \json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );

        @\file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $m, array $c = []): void { self::log('error', $m, $c); }
    public static function warning(string $m, array $c = []): void { self::log('warning', $m, $c); }
    public static function info(string $m, array $c = []): void { self::log('info', $m, $c); }
    public static function debug(string $m, array $c = []): void { self::log('debug', $m, $c); }
}
