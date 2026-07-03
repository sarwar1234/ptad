<?php

declare(strict_types=1);

/**
 * ============================================================
 * PTAD — Front Controller
 * ============================================================
 * Every web request that isn't a static asset lands here
 * (see public/.htaccess RewriteRule).
 *
 * At this stage (Step 3), this file proves the database
 * connection works end-to-end alongside the Step 2 bootstrap.
 * A real router will replace this placeholder response later.
 * ============================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Ptad\Config;
use Ptad\Database\Connection;

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

// --- Test the database connection and count tables as a sanity check ---
$dbStatus = 'not tested';
$tableCount = null;

try {
    $pdo = Connection::get();
    $stmt = $pdo->query("SHOW TABLES");
    $tableCount = $stmt->rowCount();
    $dbStatus = 'connected';
} catch (\Throwable $e) {
    $dbStatus = 'error: ' . $e->getMessage();
}

// --- Temporary placeholder response (proves the pipeline works) ---
// This will be replaced by a real router in a later step.
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'app'          => Config::get('app.name'),
    'environment'  => Config::get('env'),
    'status'       => 'Step 3 bootstrap OK',
    'database'     => $dbStatus,
    'table_count'  => $tableCount,
], JSON_PRETTY_PRINT);
