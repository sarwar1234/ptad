<?php

declare(strict_types=1);

namespace Ptad\Api;

use Ptad\Config;

/**
 * ============================================================
 * PTAD — API Response Helper
 * ============================================================
 * Single place that shapes every API JSON response, so every
 * endpoint looks and behaves consistently. Two response shapes:
 *
 *   Success: {"success": true,  "data": ..., "meta": {...}?}
 *   Error:   {"success": false, "error": {"code": "...", "message": "..."}}
 *
 * SECURITY NOTE: error() never includes internal details (SQL,
 * file paths, stack traces) in the response body in production
 * mode — those are logged server-side only via error_log(), per
 * the same dev/production split used throughout the project.
 * ============================================================
 */
final class ApiResponse
{
    public static function success(mixed $data, array $meta = [], int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        $payload = ['success' => true, 'data' => $data];
        if (!empty($meta)) {
            $payload['meta'] = $meta;
        }

        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @param string $code Short machine-readable code, e.g. 'invalid_input', 'not_found', 'server_error'.
     * @param string $publicMessage Safe to show to any caller — must NEVER contain internal details.
     * @param \Throwable|null $internalException Logged server-side only, never sent in the response.
     */
    public static function error(
        string $code,
        string $publicMessage,
        int $statusCode = 400,
        ?\Throwable $internalException = null
    ): never {
        if ($internalException !== null) {
            error_log("PTAD API error [{$code}]: " . $internalException->getMessage());
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success' => false,
            'error'   => [
                'code'    => $code,
                'message' => $publicMessage,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Converts any uncaught exception into a safe error response.
     * In development mode, includes the real exception message to
     * help debugging locally; in production, always shows a generic
     * message and logs the real one server-side only.
     */
    public static function fromException(\Throwable $e): never
    {
        $message = Config::isProduction()
            ? 'An unexpected error occurred. Please try again later.'
            : $e->getMessage();

        self::error('server_error', $message, 500, $e);
    }
}
