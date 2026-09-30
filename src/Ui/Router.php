<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\CwException;

/** Method + path -> route. `{id}` matches a positive integer. Unknown path 404, wrong method 405. */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @param \Closure(Context): HtmlResponse $handler */
    public function add(string $method, string $pattern, string $access, \Closure $handler): self
    {
        $regex = '#^' . preg_replace_callback(
            '#\{([a-z_]+)\}|[^{]+#',
            static fn (array $m): string => isset($m[1]) && $m[1] !== '' ? '(?P<' . $m[1] . '>[1-9][0-9]{0,17})' : preg_quote($m[0], '#'),
            $pattern,
        ) . '$#D';
        $this->routes[] = new Route(strtoupper($method), $pattern, $regex, $handler, $access);
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
            // HEAD is answered like GET (the body is dropped by the web server).
            if ($route->method !== $method && !($method === 'HEAD' && $route->method === 'GET')) {
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
            throw new CwException('method_not_allowed', 'use ' . implode(' or ', array_unique($allowed)) . ' for this page', 405,
                ['allow' => array_values(array_unique($allowed))]);
        }
        throw new CwException('not_found', 'no such page', 404);
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }
}
