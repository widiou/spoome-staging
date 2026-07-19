<?php

namespace Spoome\Core;

/**
 * Protezione CSRF per i form web (mutazioni di stato). Le API stateless usano invece il token Bearer.
 */
final class Csrf
{
    private static function name(): string
    {
        return (string) Config::get('CSRF_TOKEN_NAME', '_csrf');
    }

    public static function token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    /** Campo hidden pronto da inserire nei form. */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::name() . '" value="' . self::token() . '">';
    }

    public static function isValid(Request $request): bool
    {
        // Il token può arrivare nel campo del form OPPURE nell'header X-CSRF-Token (AJAX web).
        $sent = (string) $request->input(self::name(), '');
        if ($sent === '') {
            $sent = (string) ($request->header('X-CSRF-Token') ?? '');
        }
        $real = $_SESSION['_csrf_token'] ?? '';
        return $sent !== '' && is_string($real) && hash_equals($real, $sent);
    }

    /** Middleware: blocca le mutazioni web con token mancante/errato. */
    public function verify(Request $request): bool
    {
        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !self::isValid($request)) {
            Logger::security('CSRF token non valido', ['path' => $request->path]);
            if ($request->wantsJson()) {
                Response::error(I18n::t('error.csrf'), 419);
            } else {
                Response::html('<h1>419</h1><p>' . e(I18n::t('error.csrf')) . '</p>', 419);
            }
            return false;
        }
        return true;
    }
}
