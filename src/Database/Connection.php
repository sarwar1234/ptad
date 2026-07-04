<?php

declare(strict_types=1);

namespace Ptad\Database;

use PDO;
use PDOException;
use Ptad\Config;

/**
 * ============================================================
 * PTAD — Database Connection
 * ============================================================
 * Single, safe entry point for getting a PDO connection to the
 * ptad MySQL database. Used by both the web app and CLI tools
 * (loader/run.php).
 *
 * SECURITY NOTES:
 *   - Always uses prepared statements (PDO::ATTR_EMULATE_PREPARES
 *     is OFF) — this is what prevents SQL injection throughout
 *     the whole app, as long as callers use bound parameters.
 *   - Errors throw exceptions (PDO::ERRMODE_EXCEPTION) rather than
 *     silently failing or triggering PHP warnings.
 *   - Connection details (host/user/pass) come ONLY from
 *     config/config.php — never hardcoded here.
 *   - In production mode, PDO exceptions are caught and logged
 *     rather than displayed raw to the browser (which could leak
 *     the DB host/user in an error message).
 * ============================================================
 */
final class Connection
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $db = Config::get('db');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['name'],
            $db['charset']
        );

        try {
            self::$pdo = new PDO(
                $dsn,
                $db['user'],
                $db['pass'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    // Ensures the connection itself uses utf8mb4, not just
                    // the database's default charset.
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '{$db['charset']}'",
                ]
            );
        } catch (PDOException $e) {
            if (Config::isProduction()) {
                // Never leak host/credentials/DB structure to the browser
                // in production. Log the real error server-side instead.
                error_log('PTAD DB connection failed: ' . $e->getMessage());
                if (PHP_SAPI !== 'cli') {
                    http_response_code(500);
                }
                die('A server error occurred. Please try again later.');
            }

            // In development, showing the real PDO error is genuinely
            // useful for debugging local setup issues (wrong password,
            // MySQL not running, wrong database name, etc.).
            // http_response_code() only makes sense for web requests —
            // calling it under the CLI SAPI (e.g. the loader) throws a
            // PHP warning, so it's skipped there.
            if (PHP_SAPI !== 'cli') {
                http_response_code(500);
            }
            die('Database connection failed: ' . $e->getMessage());
        }

        return self::$pdo;
    }
}
