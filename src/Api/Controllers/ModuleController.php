<?php

declare(strict_types=1);

namespace Ptad\Api\Controllers;

use PDO;
use Ptad\Api\ApiResponse;
use Ptad\Api\Validator;
use Ptad\Database\Connection;

/**
 * ============================================================
 * PTAD — Module Page Endpoint
 * ============================================================
 * GET /api/modules                     List every loaded agreement (summary)
 * GET /api/modules/{code}               One agreement's full detail page
 *
 * Returns everything a "module page" needs per the Application
 * Flow document: the agreement's profile, its member countries
 * (with honest status per Country Navigator's same logic),
 * non-tariff content sections (Rules of Origin, Documentation
 * Checklist, etc. — ordered by section_types.typical_order), and
 * verification links. Does NOT include the full tariff line list
 * here — that's what /api/search is for; a module page shows
 * context, not a dump of every one of its (sometimes 15,000+)
 * tariff lines.
 * ============================================================
 */
final class ModuleController
{
    public static function listModules(): void
    {
        $pdo = Connection::get();

        $stmt = $pdo->query("
            SELECT
                a.code, a.short_name, a.full_name, a.type, a.list_type,
                a.coverage, a.status, a.entry_into_force, a.updated_at,
                (SELECT COUNT(*) FROM agreement_members WHERE agreement_id = a.id) AS member_count,
                (SELECT COUNT(*) FROM tariff_lines tl WHERE tl.agreement_id = a.id) AS tariff_line_count
            FROM agreements a
            ORDER BY a.type, a.short_name
        ");

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['summary'] = self::computeSummary($row);
            $row['last_updated'] = $row['updated_at']; // Gap C2: this field already existed and updates on every load — it was simply never surfaced.
        }
        unset($row);

        ApiResponse::success($rows);
    }

    /**
     * Gap C1 (SRS FR-2 "one-line summary"): computed on demand from
     * real structured fields, NOT stored/authored text — no clean
     * one-line summary source exists anywhere in the 29 source Excel
     * files (confirmed by direct inspection: the closest candidate,
     * each module's "Country Profile" sheet, is a market-context data
     * table, not an agreement summary sentence). Fabricating 29
     * hand-written summaries would violate the project's core "never
     * invent text" rule — this instead follows Document A's own
     * "derivable" philosophy: generate the sentence from fields
     * already known to be true, every time, consistently.
     */
    private static function computeSummary(array $agreement): string
    {
        $typeLabels = [
            'FTA' => 'Free Trade Agreement', 'PTA' => 'Preferential Trade Agreement',
            'RTA' => 'Regional Trade Arrangement', 'MTA' => 'Multilateral Trade Arrangement',
            'GSP' => 'Generalised System of Preferences scheme',
        ];
        $typeLabel = $typeLabels[$agreement['type']] ?? $agreement['type'];

        $statusPhrase = match ($agreement['status']) {
            'suspended' => 'currently suspended',
            'in_force'  => 'in force',
            default     => str_replace('_', ' ', $agreement['status']),
        };

        $sentence = "{$typeLabel}, {$statusPhrase}";

        if (!empty($agreement['entry_into_force'])) {
            $year = substr($agreement['entry_into_force'], 0, 4);
            $sentence .= " since {$year}";
        }

        if ((int) $agreement['member_count'] > 2) {
            $sentence .= ", {$agreement['member_count']} member countries";
        }

        return $sentence . '.';
    }

    public static function moduleDetail(string $code): void
    {
        $cleaned = Validator::agreementCode(strtoupper(urldecode($code)));

        if ($cleaned === null) {
            ApiResponse::error(
                'invalid_input',
                'A valid agreement code is required (letters, digits, underscores only).',
                400
            );
        }

        $pdo = Connection::get();

        $agreementStmt = $pdo->prepare("SELECT * FROM agreements WHERE code = :code LIMIT 1");
        $agreementStmt->execute([':code' => $cleaned]);
        $agreement = $agreementStmt->fetch();

        if ($agreement === false) {
            ApiResponse::error('not_found', "No agreement found with code '{$cleaned}'.", 404);
        }

        $membersStmt = $pdo->prepare("
            SELECT c.name AS country, c.iso2, c.iso3, am.role, am.status AS member_status,
                   am.status_note, am.is_ldc_in_agreement, am.member_ceiling_pct
            FROM agreement_members am
            JOIN countries c ON c.id = am.country_id
            WHERE am.agreement_id = :id
            ORDER BY c.name
        ");
        $membersStmt->execute([':id' => $agreement['id']]);

        $sectionsStmt = $pdo->prepare("
            SELECT st.code AS section_type, st.display_name AS section_name,
                   cs.title, cs.body, cs.fields, cs.hs_scope, cs.source_ref, cs.verification_url
            FROM content_sections cs
            JOIN section_types st ON st.id = cs.section_type_id
            WHERE cs.agreement_id = :id
            ORDER BY st.typical_order, cs.row_order
        ");
        $sectionsStmt->execute([':id' => $agreement['id']]);

        $linksStmt = $pdo->prepare("
            SELECT category, resource_name, purpose, url, official_source, last_updated_note
            FROM verification_links
            WHERE agreement_id = :id
            ORDER BY category
        ");
        $linksStmt->execute([':id' => $agreement['id']]);

        $tariffCountStmt = $pdo->prepare("SELECT COUNT(*) FROM tariff_lines WHERE agreement_id = :id");
        $tariffCountStmt->execute([':id' => $agreement['id']]);

        $members = $membersStmt->fetchAll();

        $agreement['summary'] = self::computeSummary(array_merge($agreement, ['member_count' => count($members)]));
        $agreement['last_updated'] = $agreement['updated_at'];

        $moduleGuidance = \Ptad\Api\GuidanceEngine::generateModuleLevel($agreement, $members);

        ApiResponse::success([
            'agreement'         => $agreement,
            'members'           => $members,
            'content_sections'  => $sectionsStmt->fetchAll(),
            'verification_links' => $linksStmt->fetchAll(),
            'tariff_line_count' => (int) $tariffCountStmt->fetchColumn(),
            'module_guidance'   => $moduleGuidance,
        ]);
    }
}
