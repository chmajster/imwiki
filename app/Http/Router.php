<?php
declare(strict_types=1);

namespace ImWiki\Http;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [strtoupper($method), $pattern, $handler];
    }

    public function get(string $pattern, callable $handler): void { $this->add('GET', $pattern, $handler); }
    public function post(string $pattern, callable $handler): void { $this->add('POST', $pattern, $handler); }
    public function put(string $pattern, callable $handler): void { $this->add('PUT', $pattern, $handler); }

    public function dispatch(Request $request): mixed
    {
        foreach ($this->routes as [$method, $pattern, $handler]) {
            if ($method !== $request->method()) {
                continue;
            }
            $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
            if ($regex === null) {
                continue;
            }
            if (preg_match('#^' . $regex . '$#', $request->path(), $matches) === 1) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return $handler($request, $params);
            }
        }
        http_response_code(404);
        return null;
    }
}
