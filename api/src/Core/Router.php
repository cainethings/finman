<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string, array<int, array{pattern:string, handler:callable}>> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->map('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->map('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->map('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->map('DELETE', $pattern, $handler);
    }

    private function map(string $method, string $pattern, callable $handler): void
    {
        $this->routes[$method][] = ['pattern' => $pattern, 'handler' => $handler];
    }

    public function dispatch(Request $request): void
    {
        if ($request->method === 'OPTIONS') {
            Response::json(['ok' => true]);
        }

        foreach ($this->routes[$request->method] ?? [] as $route) {
            $regex = preg_replace('#\{([a-zA-Z0-9_]+)\}#', '(?P<$1>[^/]+)', $route['pattern']);
            $regex = '#^' . $regex . '$#';
            if (!preg_match($regex, $request->path, $matches)) {
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $result = ($route['handler'])($request, $params);
            if (is_array($result)) {
                Response::json($result);
            }
            return;
        }

        Response::json(['message' => 'Not found'], 404);
    }
}
