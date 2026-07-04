<?php

declare(strict_types=1);

namespace PtadLoader\Support;

/**
 * ============================================================
 * PTAD — Module Config Loader
 * ============================================================
 * Reads one loader/config/{CODE}.json file and hands back a
 * plain array. Skips/ignores any file starting with "_" (those
 * are documentation, not real module configs, per the schema
 * reference file's own header comment).
 * ============================================================
 */
final class ModuleConfig
{
    public static function load(string $moduleCode): array
    {
        $path = __DIR__ . '/../config/' . $moduleCode . '.json';

        if (!file_exists($path)) {
            throw new \RuntimeException("No config found for module '{$moduleCode}' at {$path}");
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in config for '{$moduleCode}': " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Lists every real module config (excludes files starting with "_").
     * @return string[] module codes, e.g. ['IRN_PAK', 'CHN_PAK', ...]
     */
    public static function listAllModuleCodes(): array
    {
        $dir = __DIR__ . '/../config/';
        $codes = [];

        foreach (glob($dir . '*.json') as $file) {
            $base = basename($file, '.json');
            if (str_starts_with($base, '_')) {
                continue;
            }
            $codes[] = $base;
        }

        sort($codes);
        return $codes;
    }
}
