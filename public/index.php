<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Ptad\Config;
use Ptad\Api\Router;
use Ptad\Api\ApiResponse;
use Ptad\Api\RateLimiter;
use Ptad\Api\Controllers\PingController;
use Ptad\Api\Controllers\SearchController;
use Ptad\Api\Controllers\CountryController;
use Ptad\Api\Controllers\ModuleController;

Config::load();

if (Config::isProduction()) {
    ini_set('display_errors', '0');
    error_reporting(0);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

if (str_starts_with($path, '/api/')) {
    // Rate limiting applies to every API request, before routing.
    // 60 requests per 60 seconds per IP — generous for real usage,
    // enough to blunt casual scraping/abuse of a free public API.
    $limiter = new RateLimiter();
    $result = $limiter->check(RateLimiter::clientIp(), 60, 60);

    header('X-RateLimit-Remaining: ' . $result['remaining']);

    if (!$result['allowed']) {
        header('Retry-After: ' . $result['retry_after_seconds']);
        ApiResponse::error(
            'rate_limited',
            "Too many requests. Please try again in {$result['retry_after_seconds']} seconds.",
            429
        );
        exit;
    }

    $router = new Router();

    $router->get('/api/ping', [PingController::class, 'handle']);
    $router->get('/api/search', [SearchController::class, 'handle']);
    $router->get('/api/search-description', [SearchController::class, 'handleDescriptionSearch']);
    $router->get('/api/compare', [SearchController::class, 'handleCompare']);
    $router->get('/api/countries', [CountryController::class, 'listCountries']);
    $router->get('/api/countries/{name}/agreements', [CountryController::class, 'agreementsForCountry']);
    $router->get('/api/modules', [ModuleController::class, 'listModules']);
    $router->get('/api/modules/{code}', [ModuleController::class, 'moduleDetail']);

    $router->dispatch($method, $path);
    exit;
}

// --- Frontend pages ---
if ($path === '/' || $path === '') {
    require __DIR__ . '/pages/home.php';
    exit;
}
if ($path === '/search') {
    require __DIR__ . '/pages/search.php';
    exit;
}
if ($path === '/countries') {
    require __DIR__ . '/pages/countries.php';
    exit;
}
if ($path === '/modules') {
    require __DIR__ . '/pages/all-modules.php';
    exit;
}
if (preg_match('#^/modules/([A-Za-z0-9_]+)$#', $path, $m)) {
    $_GET['code'] = $m[1];
    require __DIR__ . '/pages/module.php';
    exit;
}

http_response_code(404);
echo '404 — page not found';
