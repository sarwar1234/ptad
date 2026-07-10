<?php

declare(strict_types=1);

namespace Ptad\Api\Controllers;

use PDO;
use Ptad\Api\ApiResponse;
use Ptad\Api\Validator;
use Ptad\Database\Connection;

/**
 * ============================================================
 * PTAD — Country Navigator Endpoint
 * ============================================================
 * GET /api/countries                    List every country PTAD knows about
 * GET /api/countries/{name}/agreements   All arrangements a country belongs to
 *
 * Mirrors the schema's v_agreement_membership view's intent —
 * built as an explicit query (not the raw view) so the response
 * shape stays fully within this controller's control.
 *
 * HONEST MEMBERSHIP — per the Application Flow document: a member
 * that has NOT yet implemented an agreement (confirmed real case:
 * D-8's Egypt and Nigeria) must show as "not_implemented" with a
 * clear status, NEVER as a blank or as if no data exists. This
 * endpoint returns agreement_members.status as-is for exactly
 * this reason — the frontend can render "not yet implemented"
 * honestly instead of silently omitting the member.
 * ============================================================
 */
final class CountryController
{
    public static function listCountries(): void
    {
        $pdo = Connection::get();

        $stmt = $pdo->query("
            SELECT id, iso2, iso3, name, is_ldc
            FROM countries
            ORDER BY name
        ");

        ApiResponse::success($stmt->fetchAll());
    }

    public static function agreementsForCountry(string $countryName): void
    {
        // Path segments arrive URL-encoded and may contain spaces
        // (e.g. "Sri Lanka", "United Kingdom") — decode before
        // validating/querying.
        $decoded = urldecode($countryName);
        $cleaned = Validator::searchText($decoded, 100);

        if ($cleaned === null) {
            ApiResponse::error('invalid_input', 'A valid country name is required.', 400);
        }

        $pdo = Connection::get();

        $countryStmt = $pdo->prepare("SELECT id, name, iso2, iso3, is_ldc FROM countries WHERE LOWER(name) = LOWER(:name) LIMIT 1");
        $countryStmt->execute([':name' => $cleaned]);
        $country = $countryStmt->fetch();

        if ($country === false) {
            ApiResponse::error('not_found', "No country found matching '{$cleaned}'.", 404);
        }

        $stmt = $pdo->prepare("
            SELECT
                a.code AS agreement_code,
                a.short_name AS agreement_name,
                a.type AS agreement_type,
                a.coverage,
                am.role,
                am.status AS member_status,
                am.status_note,
                am.is_ldc_in_agreement,
                am.member_ceiling_pct
            FROM agreement_members am
            JOIN agreements a ON a.id = am.agreement_id
            WHERE am.country_id = :country_id
            ORDER BY a.type, a.short_name
        ");
        $stmt->execute([':country_id' => $country['id']]);
        $agreements = $stmt->fetchAll();

        // FR-3 fix: attach the actual guidance text alongside the
        // status label for any non-implemented membership — confirmed
        // via FR traceability that Country Navigator showed the
        // status enum correctly but never the explanatory text a user
        // would need (only the module page's C1 note had it before).
        foreach ($agreements as &$agreement) {
            if (($agreement['member_status'] ?? 'implemented') !== 'implemented') {
                $agreement['guidance_note'] = 'This member has not yet started applying this agreement; please verify with customs.';
            } else {
                $agreement['guidance_note'] = null;
            }
        }
        unset($agreement);

        ApiResponse::success([
            'country'     => $country,
            'agreements'  => $agreements,
        ]);
    }
}
