<?php

declare(strict_types=1);

/**
 * ============================================================
 * PTAD — Front Controller
 * ============================================================
 * Every web request that isn't a static asset lands here
 * (see public/.htaccess RewriteRule). This bootstraps the
 * environment and will later hand off to a router.
 *
 * At this stage (Step 2), this file only proves the setup
 * works end-to-end: composer autoload, config loading, and
 * environment-aware error handling.
 * ============================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Ptad\Config;

$config = Config::load();

// --- Environment-aware error display ---
if (Config::isProduction()) {
    ini_set('display_errors', '0');
    error_reporting(0);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

// --- Temporary placeholder response (proves the pipeline works) ---
// This will be replaced by a real router in a later step.
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'app'         => Config::get('app.name'),
    'environment' => Config::get('env'),
    'status'      => 'Step 2 bootstrap OK',
], JSON_PRETTY_PRINT);
