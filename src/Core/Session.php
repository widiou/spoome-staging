<?php

namespace Spoome\Core;

/**
 * Wrapper della sessione PHP con cookie sicuri (httponly/secure/samesite) e path corretto.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => (int) Config::get('SESSION_LIFETIME', 120) * 60,
            'path'     => Config::basePath(),
            'secure'   => (bool) Config::get('SESSION_SECURE', true),
            'httponly' => (bool) Config::get('SESSION_HTTPONLY', true),
            'samesite' => (string) Config::get('SESSION_SAMESITE', 'Lax'),
        ]);
        session_name((string) Config::get('SESSION_NAME', 'spoome_session'));
        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /** True se la chiave è presente in sessione (anche se il valore è null — distingue "assente" da "null"). */
    public static function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function userId(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    /** Messaggio flash mostrato alla richiesta successiva (dopo un redirect PRG). */
    public static function flash(string $message, string $type = 'info'): void
    {
        $_SESSION['_flash'] = ['message' => $message, 'type' => $type];
    }

    /** @return array{message:string,type:string}|null */
    public static function takeFlash(): ?array
    {
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        return is_array($flash) ? $flash : null;
    }

    /** Rigenera l'ID di sessione (anti session-fixation), preservando i dati. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
