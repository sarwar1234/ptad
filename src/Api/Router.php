<?php

declare(strict_types=1);

namespace Ptad\Api;

/**
 * ============================================================
 * PTAD — API Router
 * ============================================================
 * Plain PHP router, no framework. Matches the request path
 * against a small table of exact routes and dispatches to a
 * callable. Deliberately simple: the API surface is small and
 * well-known (search, navigator, module pages, ping), so a
 * full routing library would be unjustified complexity for
 * this project's actual needs.
 *
 * Every route handler receives the validated query string
 * ($_GET) — POST/PUT/DELETE aren't used anywhere in this public,
 * read-only API (per project decision: fully public, GET-only).
 * ============================================================
 */
final class Router
{
    /** @var array<string, array<array{pattern: string, paramNames: array<string>, handler: callable}>> */
    private array $routes = [];

    /**
     * Registers a GET route. Path may include one or more {param}
     * placeholders (e.g. "/api/countries/{name}/agreements") — these
     * match any non-slash segment and are passed to the handler as
     * positional string arguments, in the order they appear in the path.
     */
    public function get(string $path, callable $handler): void
    {
        $paramNames = [];
        $regexPath = preg_replace_callback('#\{([a-zA-Z_]+)\}#', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $path);

        $this->routes['GET'][] = [
            'pattern'    => '#^' . $regexPath . '$#',
            'paramNames' => $paramNames,
            'handler'    => $handler,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                array_shift($matches); // drop the full-match at index 0
                try {
                    ($route['handler'])(...$matches);
                } catch (\Throwable $e) {
                    ApiResponse::fromException($e);
                }
                return;
            }
        }

        ApiResponse::error('not_found', 'The requested API endpoint does not exist.', 404);
    }
}
