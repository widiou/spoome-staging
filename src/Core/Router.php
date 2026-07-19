<?php

namespace Spoome\Core;

/**
 * Router: rotte statiche + segnaposto {param}, handler come callable o [Controller::class, 'metodo'],
 * con middleware opzionali per rotta. Serve sia il web (HTML) che l'API (JSON) dallo stesso dispatch.
 */
final class Router
{
    /** @var array<int, array{method:string, path:string, handler:mixed, middleware:array}> */
    private array $routes = [];

    public function add(string $method, string $path, mixed $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method'     => strtoupper($method),
            'path'       => '/' . trim($path, '/'),
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    public function get(string $p, mixed $h, array $m = []): void    { $this->add('GET', $p, $h, $m); }
    public function post(string $p, mixed $h, array $m = []): void   { $this->add('POST', $p, $h, $m); }
    public function put(string $p, mixed $h, array $m = []): void    { $this->add('PUT', $p, $h, $m); }
    public function patch(string $p, mixed $h, array $m = []): void  { $this->add('PATCH', $p, $h, $m); }
    public function delete(string $p, mixed $h, array $m = []): void { $this->add('DELETE', $p, $h, $m); }

    public function dispatch(Request $request): void
    {
        $matchedPathButNotMethod = false;

        foreach ($this->routes as $route) {
            if (!preg_match($this->compile($route['path']), $request->path, $matches)) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $matchedPathButNotMethod = true;
                continue;
            }

            $request->params = array_filter($matches, '\is_string', ARRAY_FILTER_USE_KEY);

            // middleware: se uno ritorna false, la catena si ferma (risposta già inviata)
            foreach ($route['middleware'] as $mw) {
                if ($this->runMiddleware($mw, $request) === false) {
                    return;
                }
            }

            $this->call($route['handler'], $request);
            return;
        }

        if ($matchedPathButNotMethod) {
            $this->fail($request, 405, I18n::t('error.method'));
            return;
        }
        $this->fail($request, 404, I18n::t('error.not_found'));
    }

    private function compile(string $path): string
    {
        $regex = preg_replace('#\{(\w+)\}#', '(?<$1>[^/]+)', $path);
        return '#^' . $regex . '$#';
    }

    private function runMiddleware(mixed $mw, Request $request): mixed
    {
        if (is_array($mw)) {
            [$class, $method] = $mw;
            return (new $class())->{$method}($request);
        }
        return $mw($request);
    }

    private function call(mixed $handler, Request $request): void
    {
        if (is_array($handler)) {
            [$class, $action] = $handler;
            (new $class())->{$action}($request);
            return;
        }
        $handler($request);
    }

    private function fail(Request $request, int $status, string $message): void
    {
        if ($request->wantsJson()) {
            Response::error($message, $status);
            return;
        }
        \http_response_code($status);
        $heading = $status === 404 ? I18n::t('error.not_found_title') : I18n::t('error.method');
        View::render('message', [
            'title'       => $heading . ' · ' . Config::appName(),
            'heading'     => $heading,
            'message'     => $message,
            'type'        => 'error',
            'actionUrl'   => url(''),
            'actionLabel' => I18n::t('error.back_home'),
        ], 'base');
    }
}
