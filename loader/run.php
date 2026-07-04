<?php

declare(strict_types=1);

/**
 * ============================================================
 * PTAD — Loader CLI Entry Point
 * ============================================================
 * Usage:
 *   php loader/run.php --reference        Load countries + section_types
 *   php loader/run.php --module CODE      Load one module (e.g. IRN_PAK)
 * ============================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Ptad\Config;
use PtadLoader\ReferenceLoader;
use PtadLoader\Support\ModuleConfig;
use PtadLoader\Handlers\SimpleBilateralHandler;

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
        exit(1);
    }
    exit(0);
}

$moduleIndex = array_search('--module', $args, true);
if ($moduleIndex !== false && isset($args[$moduleIndex + 1])) {
    $moduleCode = $args[$moduleIndex + 1];
    echo "Loading module: {$moduleCode}...\n\n";

    try {
        $config = ModuleConfig::load($moduleCode);
        $family = $config['format_family'];

        $handler = match ($family) {
            'simple_bilateral' => new SimpleBilateralHandler($moduleCode, $config),
            default => throw new \RuntimeException("No handler implemented yet for format_family '{$family}' (module {$moduleCode})."),
        };

        $result = $handler->run();

        echo "  ✓ Agreement ID: {$result['agreement_id']}\n";
        echo "  ✓ Tariff lines loaded: {$result['lines_loaded']}\n";

        if ($result['exceptions_count'] > 0) {
            echo "  ⚠ Exceptions logged: {$result['exceptions_count']} (see {$result['exceptions_path']})\n";
        } else {
            echo "  ✓ No exceptions.\n";
        }

        echo "\nDone.\n";
    } catch (\Throwable $e) {
        echo "\n  ✗ ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
    exit(0);
}

echo "Usage:\n";
echo "  php loader/run.php --reference        Load countries + section_types\n";
echo "  php loader/run.php --module CODE      Load one module (e.g. IRN_PAK)\n";
exit(1);
