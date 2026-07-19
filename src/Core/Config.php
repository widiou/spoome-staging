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

    public static function appName(): string
    {
        return (string) \env('APP_NAME', 'Spoome');
    }

    public static function isProduction(): bool
    {
        return self::appEnv() === 'production';
    }

    public static function isDebug(): bool
    {
        return (bool) \env('APP_DEBUG', false);
    }

    /** Prefisso pubblico dell'app (es. "/beta/"). Sempre con slash iniziale e finale. */
    public static function basePath(): string
    {
        $bp = trim((string) \env('BASE_PATH', '/'), '/');
        return $bp === '' ? '/' : '/' . $bp . '/';
    }

    /** Origin assoluto (senza slash finale), es. "https://spoome.it". APP_URL = solo origin. */
    public static function baseUrl(): string
    {
        return rtrim((string) \env('APP_URL', ''), '/');
    }

    /** URL assoluto completo per una rotta interna, incluso il BASE_PATH. Per email/redirect assoluti. */
    public static function absoluteUrl(string $path = ''): string
    {
        return self::baseUrl() . rtrim(self::basePath(), '/') . '/' . ltrim($path, '/');
    }

    /** Prefisso delle rotte API (default "/api/v1"). */
    public static function apiPrefix(): string
    {
        return '/' . trim((string) \env('API_PREFIX', '/api/v1'), '/');
    }
}
