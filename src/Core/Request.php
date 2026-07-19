<?php

namespace Spoome\Core;

/**
 * Rappresenta la richiesta HTTP corrente. Calcola la rotta interna togliendo il BASE_PATH
 * (es. "/beta/api/v1/ping" → "/api/v1/ping"), così il routing è indipendente dalla docroot.
 */
final class Request
{
    public readonly string $method;
    public readonly string $path;
    /** @var array<string,mixed> */
    public readonly array $query;
    /** @var array<string,mixed> */
    public array $params = [];

    /** @var array<string,mixed> contenitore per dati derivati (es. utente autenticato) */
    public array $attributes = [];

    /** @var array<string,mixed>|null cache del body parsato */
    private ?array $bodyCache = null;

    private function __construct(string $method, string $path, array $query)
    {
        $this->method = $method;
        $this->path   = $path;
        $this->query  = $query;
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // supporto method override per i form HTML: SOLO verso PUT/PATCH/DELETE (mai downgrade a GET,
        // che aggirerebbe il CSRF instradando un POST verso un handler read-only).
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (\in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uriPath = rawurldecode($uriPath);

        $base = rtrim(Config::basePath(), '/'); // es. "/beta"
        if ($base !== '' && str_starts_with($uriPath, $base)) {
            $uriPath = substr($uriPath, strlen($base));
        }
        $path = '/' . trim($uriPath, '/');

        return new self($method, $path, $_GET);
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, Config::apiPrefix());
    }

    public function wantsJson(): bool
    {
        return $this->isApi() || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    /** Body della richiesta: JSON o form-urlencoded, come array associativo. */
    public function body(): array
    {
        if ($this->bodyCache !== null) {
            return $this->bodyCache;
        }
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            $this->bodyCache = is_array($decoded) ? $decoded : [];
        } else {
            $this->bodyCache = $_POST;
        }
        return $this->bodyCache;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body()[$key] ?? $this->query[$key] ?? $default;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /** Valore di un header HTTP (case-insensitive), o null se assente. */
    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $val = (string) ($_SERVER[$key] ?? '');
        if ($val === '' && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp($k, $name) === 0) {
                    $val = (string) $v;
                    break;
                }
            }
        }
        return $val !== '' ? $val : null;
    }

    /** Token Bearer dall'header Authorization, se presente. */
    public function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if (preg_match('/^Bearer\s+(.+)$/i', (string) $header, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
