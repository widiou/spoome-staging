<?php

namespace Spoome\Core;

/**
 * Accesso tipizzato e centralizzato alla configurazione d'ambiente.
 * Si appoggia all'helper globale env() (config/env.php).
 */
final class Config
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return \env($key, $default);
    }

    public static function appEnv(): string
    {
        return (string) \env('APP_ENV', 'production');
    }

    public static function isProduction(): bool
    {
        return self::appEnv() === 'production';
    }

    public static function isStaging(): bool
    {
        return self::appEnv() === 'staging';
    }

    public static function isDebug(): bool
    {
        return (bool) \env('APP_DEBUG', false);
    }

    public static function basePath(): string
    {
        return \defined('BASE_PATH') ? \BASE_PATH : '/network/';
    }

    public static function baseUrl(): string
    {
        return \defined('BASE_URL') ? \BASE_URL : 'https://spoome.it/network/';
    }
}
