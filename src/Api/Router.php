<?php

declare(strict_types=1);

namespace CW\Api;

use CW\CwException;

/**
 * Method + path -> handler. Patterns are literal paths with `{name}` segments (one path
 * segment each, still percent-encoded when matched). Unknown path -> 404, known path with
 * another method -> 405 (+ the allowed methods).
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @param \Closure(Context): Response $handler */
    public function add(string $method, string $pattern, \Closure $handler, bool $stockWrite = false): self
    {
        $regex = '#^' . preg_replace_callback(
            '#\{([a-z_]+)\}|[^{]+#',
            static fn (array $m): string => isset($m[1]) && $m[1] !== '' ? '(?P<' . $m[1] . '>[^/]+)' : preg_quote($m[0], '#'),
            $pattern,
        ) . '$#D';
        $this->routes[] = new Route(strtoupper($method), $pattern, $regex, $handler, $stockWrite);
        return $this;
    }

    /** @return array{0: Route, 1: array<string, string>} */
    public function match(string $method, string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route->regex, $path, $m) !== 1) {
                continue;
            }
            if ($route->method !== $method) {
                $allowed[] = $route->method;
                continue;
            }
            $params = [];
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $params[$k] = $v;
                }
            }
            return [$route, $params];
        }
        if ($allowed !== []) {
            throw new CwException('method_not_allowed', 'use ' . implode(' or ', array_unique($allowed)) . ' for this path', 405,
                ['allow' => array_values(array_unique($allowed))]);
        }
        throw new CwException('not_found', 'no such API endpoint', 404);
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }
}
