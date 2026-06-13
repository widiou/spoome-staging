<?php

namespace Spoome\Core;

/**
 * Router minimale: rotte statiche + segnaposto {param}, dispatch a callable o
 * [Controller::class, 'metodo']. Pensato per convivere col legacy (strangler):
 * gestisce solo le rotte registrate, le altre danno 404.
 */
final class Router
{
    /** @var array<int, array{0:string,1:string,2:callable|array}> */
    private array $routes = [];

    public function add(string $method, string $path, callable|array $handler): void
    {
        $this->routes[] = [\strtoupper($method), $path, $handler];
    }

    public function get(string $path, callable|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function dispatch(string $method, string $path): void
    {
        $method = \strtoupper($method);
        $path   = '/' . \trim($path, '/');

        foreach ($this->routes as [$routeMethod, $routePath, $handler]) {
            if ($routeMethod !== $method) {
                continue;
            }
            if (\preg_match($this->compile($routePath), $path, $matches)) {
                $params = \array_filter($matches, '\is_string', ARRAY_FILTER_USE_KEY);
                $this->call($handler, $params);
                return;
            }
        }

        \http_response_code(404);
        \header('Content-Type: application/json; charset=utf-8');
        echo \json_encode(['error' => 'Not Found', 'path' => $path]);
    }

    private function compile(string $path): string
    {
        $path  = '/' . \trim($path, '/');
        $regex = \preg_replace('#\{(\w+)\}#', '(?<$1>[^/]+)', $path);
        return '#^' . $regex . '$#';
    }

    private function call(callable|array $handler, array $params): void
    {
        if (\is_array($handler)) {
            [$class, $action] = $handler;
            (new $class())->{$action}($params);
            return;
        }
        $handler($params);
    }
}
