<?php

declare(strict_types=1);

/**
 * ============================================================
 * PTAD — Loader CLI Entry Point
 * ============================================================
 * Run from the project root:
 *   php loader/run.php --reference
 *
 * This is the SAME entry point that will grow in later steps to
 * support "--module CODE" and "--all" for tariff-line loading.
 * Right now (Step 6) only reference data loading is implemented.
 * ============================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Ptad\Config;
use PtadLoader\ReferenceLoader;

// Loader is CLI-only. Refuse to run if somehow invoked via a browser.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('The loader can only be run from the command line.');
}

Config::load();

$args = array_slice($argv, 1);

echo "============================================================\n";
echo " PTAD Loader\n";
echo "============================================================\n";

if (in_array('--reference', $args, true)) {
    echo "Loading reference data (countries + section_types)...\n\n";

    try {
        $loader = new ReferenceLoader();
        $loader->run();

        foreach ($loader->getLog() as $line) {
            echo "  ✓ {$line}\n";
        }

        echo "\nDone. Reference data loaded successfully.\n";
    } catch (\Throwable $e) {
        echo "\n  ✗ ERROR: " . $e->getMessage() . "\n";
        echo "\nReference data load FAILED. Nothing further should be run until this is fixed.\n";
        exit(1);
    }

    exit(0);
}

echo "No recognised option given.\n\n";
echo "Usage:\n";
echo "  php loader/run.php --reference   Load countries + section_types\n";
echo "\n(More options — --module CODE, --all — will be added in later steps.)\n";
exit(1);
