<?php

namespace Spoome\Core;

/**
 * Helper di risposta HTTP (JSON per l'API, HTML per il web, redirect).
 * Envelope API uniforme: { data, meta, errors }.
 */
final class Response
{
    /** Risposta JSON con envelope { data, meta }. */
    public static function json(mixed $data, int $status = 200, array $meta = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $payload = ['data' => $data];
        if ($meta) {
            $payload['meta'] = $meta;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Errore JSON con envelope { errors: [...] }. */
    public static function error(string $title, int $status = 400, ?string $detail = null, array $extra = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'errors' => [array_merge(['status' => $status, 'title' => $title, 'detail' => $detail], $extra)],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function html(string $html, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    public static function text(string $body, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
    }

    public static function xml(string $body, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/xml; charset=utf-8');
        echo $body;
    }

    public static function redirect(string $path, int $status = 302): void
    {
        // Path relativi vengono prefissati col BASE_PATH; gli URL assoluti restano tali.
        if (!preg_match('#^https?://#', $path)) {
            $path = rtrim(Config::basePath(), '/') . '/' . ltrim($path, '/');
        }
        http_response_code($status);
        header('Location: ' . $path);
    }

    public static function noContent(): void
    {
        http_response_code(204);
    }
}
