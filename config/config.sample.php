<?php
/**
 * ============================================================
 * PTAD — Configuration Template
 * ============================================================
 * This is the COMMITTED template. It contains NO real secrets.
 *
 * SETUP INSTRUCTIONS:
 *   1. Copy this file to "config.php" in this same folder.
 *   2. Fill in your real local values in config.php.
 *   3. config.php is gitignored — it will NEVER be committed.
 *      Never put real passwords/keys in THIS file (config.sample.php).
 *
 * config.php is loaded by src/Database/Connection.php and by
 * loader/run.php. Nothing in "public/" ever includes this
 * directly with hardcoded values — always via config.php.
 * ============================================================
 */

return [

    // ------------------------------------------------------------
    // ENVIRONMENT
    // ------------------------------------------------------------
    // 'development'  -> verbose errors, debug logging on, relaxed CORS for local testing
    // 'production'   -> errors hidden from output (logged only), strict security headers
    //
    // IMPORTANT: This must be changed to 'production' during the
    // dedicated deployment step later. Never leave 'development'
    // on a live/public server.
    'env' => 'development',

    // ------------------------------------------------------------
    // DATABASE (MySQL / MariaDB via XAMPP locally)
    // ------------------------------------------------------------
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'ptad',
        'charset' => 'utf8mb4',

        // Create a DEDICATED MySQL user for this app — never use 'root'.
        // We will create this user together in Step 3 (database setup).
        'user'    => 'ptad_app_user',
        'pass'    => 'CHANGE_ME_LOCALLY',
    ],

    // ------------------------------------------------------------
    // APPLICATION
    // ------------------------------------------------------------
    'app' => [
        'name'      => 'PTAD - Preferential Trade Access Database',
        'base_url'  => 'http://ptad.local',   // will become https://ptad.tdap.gov.pk in production
        'timezone'  => 'Asia/Karachi',
    ],

    // ------------------------------------------------------------
    // SECURITY
    // ------------------------------------------------------------
    'security' => [
        // Random, unique, long string. Generate your own — never reuse
        // this example. Used for session/token integrity, NOT for
        // any external service.
        // Generate one with: php -r "echo bin2hex(random_bytes(32));"
        'app_secret' => 'CHANGE_ME_GENERATE_YOUR_OWN_RANDOM_STRING',

        // Session cookie hardening (used when we build the admin area)
        'session_name'     => 'ptad_session',
        'session_lifetime' => 1800, // 30 minutes idle timeout
    ],

    // ------------------------------------------------------------
    // PATHS (for the loader — CLI only, never web-exposed)
    // ------------------------------------------------------------
    'paths' => [
        'data_dir'   => __DIR__ . '/../data',
        'loader_logs' => __DIR__ . '/../loader/logs',
    ],

];
