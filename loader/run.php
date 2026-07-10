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

// This is a CLI-only batch script that can process 25+ multi-megabyte
// Excel files in one run — raising the memory limit here is safe and
// has NO effect on the website's PHP processes (php.ini's web-facing
// memory_limit for Apache/PHP-FPM is untouched; this ini_set only
// applies to this one CLI process).
ini_set('memory_limit', '2048M');

use Ptad\Config;
use PtadLoader\ReferenceLoader;
use PtadLoader\Support\ModuleConfig;
use PtadLoader\Handlers\SimpleBilateralHandler;
use PtadLoader\Handlers\TextRateHandler;
use PtadLoader\Handlers\MultiCountryLdcHandler;
use PtadLoader\Handlers\PhasedCalendarYearHandler;
use PtadLoader\Handlers\SelectiveConcessionHandler;
use PtadLoader\Handlers\PhasedMultiComponentHandler;
use PtadLoader\Handlers\NegativeListHandler;

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
        $result = runModule($moduleCode);
        printModuleResult($moduleCode, $result);
        echo "\nDone.\n";
    } catch (\Throwable $e) {
        echo "\n  ✗ ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
    exit(0);
}

if (in_array('--all', $args, true)) {
    $codes = ModuleConfig::listAllModuleCodes();
    echo "Found " . count($codes) . " module configs. Attempting to load each...\n\n";

    $loaded = [];
    $skipped = [];
    $failed = [];

    foreach ($codes as $moduleCode) {
        echo "--- {$moduleCode} ---\n";
        try {
            $result = runModule($moduleCode);
            printModuleResult($moduleCode, $result);
            $loaded[] = $moduleCode;
        } catch (\RuntimeException $e) {
            // "No handler implemented yet" lands here — treated as a
            // skip, not a failure, since it's expected until that
            // format family's handler is built in a later step.
            if (str_contains($e->getMessage(), 'No handler implemented yet')) {
                echo "  ⏭ SKIPPED — {$e->getMessage()}\n";
                $skipped[] = $moduleCode;
            } else {
                echo "  ✗ FAILED — {$e->getMessage()}\n";
                $failed[] = $moduleCode;
            }
        } catch (\Throwable $e) {
            echo "  ✗ FAILED — {$e->getMessage()}\n";
            $failed[] = $moduleCode;
        }
        echo "\n";
    }

    echo "============================================================\n";
    echo " SUMMARY\n";
    echo "============================================================\n";
    echo "  Loaded  (" . count($loaded)  . "): " . implode(', ', $loaded)  . "\n";
    echo "  Skipped (" . count($skipped) . "): " . implode(', ', $skipped) . "  [handler not built yet — normal for now]\n";
    echo "  Failed  (" . count($failed)  . "): " . implode(', ', $failed)  . "\n";

    exit(count($failed) > 0 ? 1 : 0);
}

if (in_array('--dedupe-remarks', $args, true)) {
    echo "Deduplicating staging remarks into staging_remarks table...\n\n";
    $dedup = new \PtadLoader\StagingRemarksDeduplicator();
    $results = $dedup->run();
    $totalDistinct = 0;
    $totalLinked = 0;
    foreach ($results as $code => $r) {
        echo "  {$code}: {$r['distinct_remarks']} distinct remarks, {$r['lines_linked']} lines linked\n";
        $totalDistinct += $r['distinct_remarks'];
        $totalLinked += $r['lines_linked'];
    }
    echo "\nTotal: {$totalDistinct} distinct remarks, {$totalLinked} lines linked.\n";
    exit(0);
}

if (in_array('--validate-advantage', $args, true)) {
    $validator = new \PtadLoader\AdvantageValidator();
    $validator->report();
    exit(0);
}

if (in_array('--quota-notes', $args, true)) {
    echo "Extracting quota/season notes from remarks text...\n\n";

    $extractor = new \PtadLoader\QuotaNoteExtractor();
    $result = $extractor->run();

    echo "  Lines updated: {$result['lines_updated']}\n";
    echo "  Quota matches: {$result['quota_matches']}\n";
    echo "  Season matches: {$result['season_matches']}\n";
    exit(0);
}

if (in_array('--coverage-rules', $args, true)) {
    echo "Loading coverage rules (EAEU modules only)...\n\n";

    $loader = new \PtadLoader\CoverageRulesLoader();
    $result = $loader->loadAll();
    $loader->closeLog();

    foreach ($result['per_module'] as $code => $status) {
        echo "  {$code}: {$status}\n";
    }
    echo "\nTotal: {$result['total']} coverage rules loaded.\n";
    exit(0);
}

if (in_array('--sections', $args, true)) {
    echo "Loading content sections + verification links for all agreements...\n\n";

    $pdo = \Ptad\Database\Connection::get();
    $agreements = $pdo->query("SELECT id, code, source_workbook FROM agreements ORDER BY code")->fetchAll();

    $loader = new \PtadLoader\ContentSectionsLoader();
    $totalSections = 0;
    $totalLinks = 0;

    foreach ($agreements as $a) {
        try {
            $result = $loader->loadForModule($a['code'], $a['source_workbook'], (int) $a['id']);
            echo "  {$a['code']}: {$result['sections_loaded']} sections, {$result['links_loaded']} links\n";
            $totalSections += $result['sections_loaded'];
            $totalLinks += $result['links_loaded'];
        } catch (\Throwable $e) {
            echo "  {$a['code']}: ERROR — " . $e->getMessage() . "\n";
        }
    }

    $loader->closeLog();

    echo "\nTotal: {$totalSections} sections, {$totalLinks} verification links loaded.\n";
    exit(0);
}

echo "Usage:\n";
echo "  php loader/run.php --reference        Load countries + section_types\n";
echo "  php loader/run.php --module CODE      Load one module (e.g. IRN_PAK)\n";
echo "  php loader/run.php --all              Load every module with a working handler, skip the rest\n";
echo "  php loader/run.php --sections          Load content sections + verification links for all agreements\n";
exit(1);

/**
 * Runs one module's config through the correct handler based on its
 * format_family. Throws RuntimeException("No handler implemented yet...")
 * for families not yet built — this specific message is what --all
 * uses to distinguish an expected "not ready yet" skip from a real failure.
 */
function runModule(string $moduleCode): array
{
    $config = ModuleConfig::load($moduleCode);
    $family = $config['format_family'];

    $handler = match ($family) {
        'simple_bilateral' => new SimpleBilateralHandler($moduleCode, $config),
        'text_rate' => new TextRateHandler($moduleCode, $config),
        'multi_country_ldc' => new MultiCountryLdcHandler($moduleCode, $config),
        'phased_calendar_year' => new PhasedCalendarYearHandler($moduleCode, $config),
        'selective_concession' => new SelectiveConcessionHandler($moduleCode, $config),
        'phased_multi_component' => new PhasedMultiComponentHandler($moduleCode, $config),
        'negative_list' => new NegativeListHandler($moduleCode, $config),
        default => throw new \RuntimeException("No handler implemented yet for format_family '{$family}' (module {$moduleCode})."),
    };

    return $handler->run();
}

function printModuleResult(string $moduleCode, array $result): void
{
    echo "  ✓ Agreement ID: {$result['agreement_id']}\n";
    echo "  ✓ Tariff lines loaded: {$result['lines_loaded']}\n";
    if ($result['exceptions_count'] > 0) {
        echo "  ⚠ Exceptions logged: {$result['exceptions_count']} (see {$result['exceptions_path']})\n";
    } else {
        echo "  ✓ No exceptions.\n";
    }
}
