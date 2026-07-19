<?php

namespace Spoome\Core;

use Throwable;

/**
 * Gestore centralizzato di errori/eccezioni. In debug mostra il dettaglio; in produzione
 * logga e mostra una risposta generica (JSON se richiesta API, HTML altrimenti).
 */
final class ErrorHandler
{
    /** Dettaglio verboso al client SOLO in sviluppo locale: in staging/prod non si espone mai lo stacktrace. */
    private static function verbose(): bool
    {
        return Config::appEnv() === 'local' && Config::isDebug();
    }

    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', self::verbose() ? '1' : '0');

        set_exception_handler([self::class, 'handle']);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        register_shutdown_function(static function (): void {
            $e = error_get_last();
            if ($e && \in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::handle(new \ErrorException($e['message'], 0, $e['type'], $e['file'], $e['line']));
            }
        });
    }

    public static function handle(Throwable $e): void
    {
        Logger::error($e->getMessage(), [
            'exception' => $e::class,
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);

        if (!headers_sent()) {
            http_response_code(500);
        }

        $wantsJson = str_contains($_SERVER['REQUEST_URI'] ?? '', Config::apiPrefix())
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'errors' => [[
                    'status' => 500,
                    'title'  => I18n::t('api.error.internal'),
                    'detail' => self::verbose() ? $e->getMessage() : null,
                ]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        if (self::verbose()) {
            echo '<h1>Errore</h1><pre>' . htmlspecialchars((string) $e, ENT_QUOTES) . '</pre>';
        } else {
            echo '<h1>' . htmlspecialchars(I18n::t('error.generic_title'), ENT_QUOTES) . '</h1><p>'
                . htmlspecialchars(I18n::t('error.generic_body'), ENT_QUOTES) . '</p>';
        }
    }
}
