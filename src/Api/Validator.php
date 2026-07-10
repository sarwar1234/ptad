<?php

declare(strict_types=1);

namespace Ptad\Api;

/**
 * ============================================================
 * PTAD — API Input Validation
 * ============================================================
 * Every endpoint MUST validate raw user input through these
 * helpers before it touches the database or business logic —
 * no endpoint should read $_GET/$_POST directly. This is the
 * "never trust user input" boundary for the whole API.
 *
 * All queries against the database still use PDO prepared
 * statements (see Connection.php) — this class handles shape/
 * format validation (is it a plausible HS code? a real integer?
 * within an allowed length?), not SQL escaping, which PDO already
 * handles correctly on its own.
 * ============================================================
 */
final class Validator
{
    /**
     * Validates a raw HS code search query from the user. Allows
     * digits, dots, and spaces only (matches every real HS code
     * format seen across all 29 modules) — rejects anything else
     * up front, before it ever reaches HsCode::normalize() or a
     * database query.
     */
    public static function hsCodeQuery(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        if ($trimmed === '' || mb_strlen($trimmed) > 20) {
            return null;
        }

        if (!preg_match('/^[0-9. ]+$/', $trimmed)) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Validates a free-text product-description search query.
     * Length-capped and stripped of control characters; the actual
     * SQL safety comes from prepared statements, this just rejects
     * obviously-invalid input early (e.g. absurdly long strings
     * that could only be an attempted attack or accidental paste).
     */
    public static function searchText(?string $raw, int $maxLength = 200): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        if ($trimmed === '' || mb_strlen($trimmed) > $maxLength) {
            return null;
        }

        // Strip control characters (e.g. null bytes) that have no
        // legitimate place in a search query.
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/u', '', $trimmed);

        return $cleaned === '' ? null : $cleaned;
    }

    /**
     * Validates an agreement code parameter (e.g. "IRN_PAK") against
     * the pattern every real config file actually uses — letters,
     * digits, underscores only.
     */
    public static function agreementCode(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        if (!preg_match('/^[A-Z0-9_]{2,50}$/', $trimmed)) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Validates a positive integer parameter (e.g. an ID, a page
     * number), with a sensible default and hard maximum to prevent
     * abuse (e.g. someone requesting page=999999999).
     */
    public static function positiveInt(?string $raw, int $default, int $max = 1000000): int
    {
        if ($raw === null || $raw === '') {
            return $default;
        }

        if (!ctype_digit($raw)) {
            return $default;
        }

        $value = (int) $raw;

        if ($value < 1) {
            return $default;
        }

        return min($value, $max);
    }

    /**
     * Validates a "limit" query parameter for pagination, capped at
     * a hard maximum so no request can force the API to return an
     * unbounded number of rows.
     */
    public static function limit(?string $raw, int $default = 50, int $max = 500): int
    {
        return self::positiveInt($raw, $default, $max);
    }
}
