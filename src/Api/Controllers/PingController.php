<?php

declare(strict_types=1);

namespace Ptad\Api\Controllers;

use Ptad\Api\ApiResponse;
use Ptad\Database\Connection;

/**
 * ============================================================
 * PTAD — Ping Endpoint
 * ============================================================
 * GET /api/ping
 *
 * Proves the API pipeline works end-to-end: router -> controller
 * -> database -> ApiResponse. Not meant for real use beyond
 * confirming the plumbing works before building real endpoints
 * on top of it (same purpose as our earlier Step 3 test, now
 * routed through the real API structure instead of index.php
 * directly).
 * ============================================================
 */
final class PingController
{
    public static function handle(): void
    {
        $pdo = Connection::get();

        $agreementCount = (int) $pdo->query("SELECT COUNT(*) FROM agreements")->fetchColumn();
        $tariffLineCount = (int) $pdo->query("SELECT COUNT(*) FROM tariff_lines")->fetchColumn();

        ApiResponse::success([
            'status'           => 'ok',
            'agreements_count' => $agreementCount,
            'tariff_lines_count' => $tariffLineCount,
        ]);
    }
}
