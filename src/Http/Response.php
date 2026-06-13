<?php

namespace Spoome\Http;

/**
 * Helper di risposta HTTP per i controller MVC.
 */
final class Response
{
    public static function json(mixed $data, int $status = 200): void
    {
        \http_response_code($status);
        \header('Content-Type: application/json; charset=utf-8');
        echo \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
