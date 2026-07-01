<?php

declare(strict_types=1);

namespace Ptad;

/**
 * ============================================================
 * PTAD — Config Loader
 * ============================================================
 * Single, safe entry point for reading config/config.php.
 * Used by both the web app (public/index.php) and CLI tools
 * (loader/run.php) so there is only ONE place that knows how
 * to find and validate the config file.
 * ============================================================
 */
final class Config
{
    /** @var array<string,mixed>|null */
    private static ?array $config = null;

    public static function load(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }


        $path = __DIR__ . '/../config/config.php';


        if (!file_exists($path)) {
            // Fail loudly and safely — never fall back to hardcoded
            // production-looking defaults, and never leak the full
            // server path in the message.
            http_response_code(500);
            error_log('PTAD FATAL: config/config.php not found. Copy config/config.sample.php to config/config.php and fill in real values.');
            die('Configuration error. Please contact the administrator.');
        }

        /** @var array<string,mixed> $config */
        $config = require $path;
        self::$config = $config;

        return self::$config;
    }

    public static function get(string $dotKey, mixed $default = null): mixed
    {
        $config = self::load();
        $segments = explode('.', $dotKey);
        $value = $config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function isProduction(): bool
    {
        return self::get('env', 'development') === 'production';
    }
}
