<?php

declare(strict_types=1);

namespace Ptad\Api\Controllers;

use PDO;
use Ptad\Api\ApiResponse;
use Ptad\Api\Validator;
use Ptad\Database\Connection;
use Ptad\Helpers\HsCode;

/**
 * ============================================================
 * PTAD — HS Code Search Endpoint
 * ============================================================
 * GET /api/search?hs_code=0802.1200
 * GET /api/search?hs_code=0802&year=2026   (optional year filter)
 *
 * The core endpoint: "give me everything for this HS code across
 * all 29 arrangements at once" — mirrors the schema's
 * v_tariff_search view's intent, built here as an explicit query
 * (rather than querying the view directly) so SAFTA's inverted
 * logic can be layered on top in PHP, since that logic is
 * business-rule-level, not something the raw view alone expresses.
 *
 * Accepts partial HS codes as a PREFIX match (searching "0802"
 * matches "0802.1200", "0802.1300", etc.), matching the documented
 * "type first 2/4/6/8 digits" search behaviour seen across the
 * source workbooks themselves.
 *
 * SAFTA'S INVERTED LOGIC — handled explicitly here, not silently:
 *   - If the searched code IS in SAFTA's data (is_excluded=1),
 *     it's returned normally like any other agreement's result,
 *     clearly marked as excluded/no concession.
 *   - If the searched code is NOT found in SAFTA's data at all,
 *     a SYNTHESIZED result is added explicitly labeled
 *     "not on the sensitive list — eligible up to the ceiling",
 *     using the agreement's default_ceiling_pct. This is never
 *     silently omitted — the docs specifically require SAFTA
 *     to give an honest answer either way.
 *
 * MULTI-YEAR MODULES (China, D-8, Türkiye): defaults to the rate
 * for the CURRENT calendar year (or the closest available year)
 * unless a specific ?year= is requested, rather than returning
 * every phased year row on every search (which would be
 * overwhelming and isn't what a search result should show by
 * default — matches the Application Flow's example of a single
 * relevant answer per agreement).
 * ============================================================
 */
final class SearchController
{
    public static function handle(): void
    {
        $rawHsCode = $_GET['hs_code'] ?? null;
        $hsCodeQuery = Validator::hsCodeQuery($rawHsCode);

        if ($hsCodeQuery === null) {
            ApiResponse::error(
                'invalid_input',
                'A valid hs_code query parameter is required (digits, dots, and spaces only, max 20 characters).',
                400
            );
        }

        $normalized = HsCode::normalize($hsCodeQuery);
        $prefix = $normalized['norm'];

        // Distinguish "user explicitly asked for year X" from "no year
        // given, use the correct default" — these need different
        // behaviour. An explicit request always wins outright, exactly
        // as before. With no explicit request, the default is computed
        // PER AGREEMENT below, since a flat "current calendar year"
        // default is WRONG for anniversary-staged modules like Türkiye
        // PTA, whose "year" boundary falls on 1 May, not 1 January —
        // confirmed by entry_into_force=2023-05-01 and
        // anniversary_month/day=5/1 in that agreement's own data.
        $explicitYear = $_GET['year'] ?? null;
        $requestedYear = $explicitYear !== null
            ? Validator::positiveInt($explicitYear, (int) date('Y'), 2100)
            : null;

        $pdo = Connection::get();

        $results = self::searchNormalModules($pdo, $prefix, $requestedYear);
        $safta = self::searchSafta($pdo, $prefix);

        if ($safta !== null) {
            $results[] = $safta;
        }

        $totalMatches = count($results);

        // Pagination: applied AFTER building the full result set (not
        // in SQL), since the per-line year/component selection logic
        // above already requires assembling the complete picked-rows
        // array in PHP first — slicing that array is simple and safe
        // given result counts here are at most a few hundred, not
        // millions of rows.
        $limit = Validator::limit($_GET['limit'] ?? null, 100, 500);
        $rawOffset = $_GET['offset'] ?? null;
        $offset = ($rawOffset !== null && ctype_digit((string) $rawOffset)) ? min((int) $rawOffset, 100000) : 0;

        $pagedResults = array_slice($results, $offset, $limit);

        ApiResponse::success($pagedResults, [
            'query'         => $hsCodeQuery,
            'normalized'    => $prefix,
            'year_mode'     => $requestedYear !== null ? 'explicit' : 'per_agreement_default',
            'year'          => $requestedYear, // null when computed per-agreement instead
            'result_count'  => count($pagedResults),
            'total_matches' => $totalMatches,
            'limit'         => $limit,
            'offset'        => $offset,
            'has_more'      => ($offset + count($pagedResults)) < $totalMatches,
        ]);
    }

    /**
     * Searches every agreement EXCEPT SAFTA. Fetches ALL rate rows for
     * matching lines (every year/component, unfiltered), then picks
     * the single correct rate row PER (line, component) in PHP —
     * because which "year" is correct depends on each agreement's own
     * staging rules (anniversary vs calendar_year vs none), not a
     * single global filter applied equally to every module.
     */
    private static function searchNormalModules(PDO $pdo, string $prefix, ?int $requestedYear): array
    {
        $sql = "
            SELECT
                tl.id AS line_id,
                a.id AS agreement_id,
                a.code AS agreement_code,
                a.short_name AS agreement_name,
                a.type AS agreement_type,
                a.coverage,
                a.status,
                a.staging,
                a.anniversary_month,
                a.anniversary_day,
                ic.name AS import_country,
                mc.name AS member_country,
                tl.hs_code_raw,
                tl.hs_code_norm,
                tl.product_desc,
                tl.mfn_kind,
                tl.mfn_value,
                tl.mfn_text,
                tl.mfn_meaning,
                tl.is_excluded,
                tl.staging_category,
                COALESCE(tl.remarks, sr.remark_text) AS remarks,
                tr.id AS rate_id,
                tr.`condition`,
                tr.component,
                tr.applies_year,
                tr.rate_kind,
                tr.rate_value,
                tr.rate_text,
                tr.effective_advalorem,
                tr.concession_type,
                tr.concession_pct,
                tr.outcome_value,
                tr.outcome_text,
                tr.advantage_value,
                tr.advantage_text,
                tr.quota_note,
                tr.season_note,
                am.status AS member_status
            FROM tariff_lines tl
            JOIN agreements a ON a.id = tl.agreement_id
            LEFT JOIN countries ic ON ic.id = tl.import_country_id
            LEFT JOIN countries mc ON mc.id = tl.member_country_id
            LEFT JOIN tariff_rates tr ON tr.tariff_line_id = tl.id
            LEFT JOIN agreement_members am ON am.agreement_id = a.id
                AND am.country_id = COALESCE(tl.member_country_id, tl.import_country_id)
            LEFT JOIN staging_remarks sr ON sr.id = tl.staging_remark_id
            WHERE a.code != 'SAFTA'
              AND tl.hs_code_norm LIKE :prefix
            ORDER BY a.code, tl.hs_code_norm, tr.component, tr.applies_year
            LIMIT 5000
        ";
        // NOTE: LIMIT raised from 500 to 5000 rows here because a single
        // phased line can now return many rows (all years x all
        // components) before PHP narrows it down to just the correct
        // ones below — 500 was sized for the old one-row-per-component
        // shape and would have silently truncated a heavily-phased
        // module's results (e.g. Türkiye's 3 components x 11 years).

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':prefix' => $prefix . '%']);
        $allRows = $stmt->fetchAll();

        // Group all fetched rows by (line_id, component) so we can pick
        // exactly one rate row per component per line.
        $groups = [];
        foreach ($allRows as $row) {
            $key = $row['line_id'] . '|' . ($row['component'] ?? 'total');
            $groups[$key][] = $row;
        }

        $picked = [];
        foreach ($groups as $rowsForGroup) {
            $picked[] = self::pickCorrectYearRow($rowsForGroup, $requestedYear);
        }

        // Group F1 detection: within the same (agreement, import/member
        // country, hs_code_norm) group, if more than one line exists
        // sharing the SAME effective rate, and this specific line's
        // remarks contain "Statistical Key" (confirmed real marker —
        // New Zealand/Australia sub-line rows), it's a statistical
        // sub-line of whichever sibling line does NOT have that marker.
        $byHsGroup = [];
        foreach ($picked as $idx => $row) {
            $groupKey = $row['agreement_code'] . '|' . ($row['import_country'] ?? $row['member_country']) . '|' . $row['hs_code_norm'];
            $byHsGroup[$groupKey][] = $idx;
        }
        foreach ($byHsGroup as $indices) {
            if (count($indices) < 2) {
                continue; // no siblings, can't be a sub-line
            }
            foreach ($indices as $idx) {
                if (str_contains($picked[$idx]['remarks'] ?? '', 'Statistical Key')) {
                    $picked[$idx]['is_substatistical'] = true;
                }
            }
        }

        // Gap A6 (SRS FR-16): for Türkiye's multi-component duty
        // (CD/RD/ACD), compute and attach the COMBINED result to each
        // component row of the same line/year — FR-16 explicitly
        // requires showing "each component AND the combined result."
        // Standard trade practice (confirmed: no source remark states
        // otherwise for this module, since Türkiye's remarks were
        // nulled out per the earlier formula-contamination fix) is
        // that CD/RD/ACD are independently-levied percentages of
        // customs value, summed for the total duty burden — NOT
        // compounded on each other. Only computed when EVERY non-null
        // component in the group is a clean ad_valorem percentage;
        // if any component is a specific duty or unspecified, no
        // combined percentage is fabricated — left null instead.
        $byLineYear = [];
        foreach ($picked as $idx => $row) {
            if (($row['agreement_code'] ?? null) !== 'TUR_PAK') {
                continue;
            }
            $key = $row['line_id'] . '|' . ($row['applies_year'] ?? 'none');
            $byLineYear[$key][] = $idx;
        }
        foreach ($byLineYear as $indices) {
            $sum = 0.0;
            $allCleanPercent = true;
            $anyValue = false;

            foreach ($indices as $idx) {
                $rateKind = $picked[$idx]['rate_kind'] ?? null;
                $rateValue = $picked[$idx]['rate_value'] ?? null;

                if ($rateValue === null) {
                    continue; // component not applicable to this line — contributes 0, not an error
                }
                if ($rateKind !== 'ad_valorem') {
                    $allCleanPercent = false;
                    break;
                }
                $sum += (float) $rateValue;
                $anyValue = true;
            }

            if ($allCleanPercent && $anyValue) {
                foreach ($indices as $idx) {
                    $picked[$idx]['combined_advalorem'] = round($sum, 4);
                }
            }
        }

        // Coverage rule check (Gap A2, SRS FR-20): only the 5 EAEU
        // modules currently have coverage_rules data (confirmed —
        // Türkiye GSP's exclusions exist only as free text, no
        // structured rules to check against). Runs per picked row,
        // comparing the chapter-level rule against the line's own
        // stated eligibility (already in remarks), flagging disagreement.
        $eaeuCodes = ['GSP_RUS', 'GSP_ARM', 'GSP_BLR', 'GSP_KAZ', 'GSP_KGZ'];
        foreach ($picked as &$row) {
            if (in_array($row['agreement_code'], $eaeuCodes, true)) {
                $coverage = \Ptad\Api\CoverageRuleChecker::check(
                    $pdo, (int) $row['agreement_id'], $row['hs_code_norm'], $row['remarks'] ?? null
                );
                $row['coverage_rule_check'] = $coverage;
            }
        }
        unset($row);

        // Gap B2 (Document A §3.4: "add LINKS (not text) to deeper
        // sections by topic"): attach agreement-wide topic links to
        // each result. NOT HS-code-specific — confirmed content_sections
        // .hs_scope is never populated (0 of 2,077 rows) across the
        // whole database, so a genuinely product-specific link (e.g.
        // "the Rules of Origin rule for THIS HS code") cannot be built
        // honestly yet; that's tracked separately (Gap C3). This gives
        // links to the correct MODULE-WIDE sections, not their content —
        // the actual text stays in one place (content_sections), never
        // copied onto search results, per Document A's core principle.
        $sectionTopicsByAgreement = [];
        foreach ($picked as $row) {
            $sectionTopicsByAgreement[$row['agreement_id']] = true;
        }
        if (!empty($sectionTopicsByAgreement)) {
            $topicsStmt = $pdo->prepare("
                SELECT DISTINCT cs.agreement_id, st.code, st.display_name
                FROM content_sections cs
                JOIN section_types st ON st.id = cs.section_type_id
                WHERE cs.agreement_id IN (" . implode(',', array_fill(0, count($sectionTopicsByAgreement), '?')) . ")
                ORDER BY st.typical_order
            ");
            $topicsStmt->execute(array_keys($sectionTopicsByAgreement));
            $topicsByAgreement = [];
            foreach ($topicsStmt->fetchAll() as $t) {
                $topicsByAgreement[$t['agreement_id']][] = ['topic' => $t['code'], 'label' => $t['display_name']];
            }

            foreach ($picked as &$row) {
                $row['related_sections'] = array_map(
                    fn($t) => ['topic' => $t['topic'], 'label' => $t['label'], 'link' => "/api/modules/{$row['agreement_code']}#{$t['topic']}"],
                    $topicsByAgreement[$row['agreement_id']] ?? []
                );
            }
            unset($row);
        }

        // FR-12 / Document A: never show a computed advantage where the
        // preferential rate is a "verify externally" placeholder
        // (rate_kind unspecified/text_only) or equals the base rate
        // (zero margin) — showing a number in either case would
        // misleadingly imply a real, quantified benefit. Confirmed via
        // FR-1..34 traceability check that advantage_value/text were
        // never even exposed in the API before this fix; now exposed,
        // but correctly suppressed for exactly these two cases.
        foreach ($picked as &$row) {
            $rateKind = $row['rate_kind'] ?? null;
            $isZeroMargin = $row['mfn_value'] !== null && $row['effective_advalorem'] !== null
                && abs((float) $row['mfn_value'] - (float) $row['effective_advalorem']) < 0.0001;

            if (in_array($rateKind, ['unspecified', 'text_only'], true) || $isZeroMargin) {
                $row['advantage_value'] = null;
                $row['advantage_text'] = null;
            }
        }
        unset($row);

        // Attach guidance notes to each finally-picked row.
        foreach ($picked as &$row) {
            $row['guidance'] = \Ptad\Api\GuidanceEngine::generate($row, $row);
        }
        unset($row);

        return $picked;
    }

    /**
     * Given every rate row available for one (line, component) pair,
     * picks the single correct one:
     *   - A standing rate (applies_year IS NULL) is the only option
     *     when present — return it directly.
     *   - An explicit ?year= request matches that year exactly.
     *   - Otherwise, the default year is computed from the agreement's
     *     OWN staging type: 'anniversary' modules compute today's
     *     position relative to anniversary_month/day (before the
     *     anniversary this calendar year -> use last year's phase;
     *     on/after -> use this year's); everything else just uses
     *     the current calendar year.
     */
    private static function pickCorrectYearRow(array $rowsForGroup, ?int $requestedYear): array
    {
        // Standing rate (no phasing at all for this line/component).
        foreach ($rowsForGroup as $row) {
            if ($row['applies_year'] === null) {
                return $row;
            }
        }

        $first = $rowsForGroup[0];
        $targetYear = $requestedYear ?? self::computeDefaultYear(
            $first['staging'] ?? 'none',
            $first['anniversary_month'] ?? null,
            $first['anniversary_day'] ?? null
        );

        foreach ($rowsForGroup as $row) {
            if ((int) $row['applies_year'] === $targetYear) {
                return $row;
            }
        }

        // Requested/computed year isn't in range for this line (e.g.
        // asked for a year before the schedule started) — fall back to
        // the closest available year rather than returning nothing,
        // so the caller still sees SOMETHING for this line.
        usort($rowsForGroup, fn($a, $b) =>
            abs((int) $a['applies_year'] - $targetYear) <=> abs((int) $b['applies_year'] - $targetYear)
        );

        return $rowsForGroup[0];
    }

    /**
     * Computes the correct "current" phasing year for TODAY, per the
     * agreement's own staging type.
     */
    private static function computeDefaultYear(string $staging, ?int $anniversaryMonth, ?int $anniversaryDay): int
    {
        $currentYear = (int) date('Y');

        if ($staging !== 'anniversary' || $anniversaryMonth === null || $anniversaryDay === null) {
            return $currentYear;
        }

        $todayMonth = (int) date('n');
        $todayDay = (int) date('j');

        // Before this year's anniversary date -> still in last year's
        // phase (e.g. Türkiye's year changes 1 May: on 15 March 2027,
        // we are still in the "2026" phase, which runs 1 May 2026
        // through 30 April 2027).
        if ($todayMonth < $anniversaryMonth || ($todayMonth === $anniversaryMonth && $todayDay < $anniversaryDay)) {
            return $currentYear - 1;
        }

        return $currentYear;
    }

    /**
     * SAFTA's inverted logic, made explicit rather than left implicit
     * in a generic query. Returns null if the search prefix matches
     * nothing meaningful for SAFTA context (e.g. an obviously invalid
     * code) — otherwise always returns SOMETHING: either the real
     * excluded-line data if found, or a synthesized eligible-up-to-
     * ceiling result if not found on any member's sensitive list.
     */
    private static function searchSafta(PDO $pdo, string $prefix): ?array
    {
        $agreementStmt = $pdo->prepare("SELECT id, default_ceiling_pct FROM agreements WHERE code = 'SAFTA'");
        $agreementStmt->execute();
        $agreement = $agreementStmt->fetch();

        if ($agreement === false) {
            return null; // SAFTA not loaded yet — nothing to report
        }

        $stmt = $pdo->prepare("
            SELECT
                c.name AS member_country,
                tl.hs_code_raw,
                tl.hs_code_norm,
                tl.product_desc,
                tl.is_excluded,
                tl.remarks
            FROM tariff_lines tl
            JOIN countries c ON c.id = tl.member_country_id
            WHERE tl.agreement_id = :agreement_id
              AND tl.hs_code_norm LIKE :prefix
        ");
        $stmt->execute([
            ':agreement_id' => $agreement['id'],
            ':prefix'       => $prefix . '%',
        ]);
        $excludedRows = $stmt->fetchAll();

        if (!empty($excludedRows)) {
            // Found on at least one member's sensitive list — return
            // the real excluded data, per-member, exactly as stored.
            return [
                'agreement_code' => 'SAFTA',
                'agreement_name' => 'South Asian Free Trade Area (SAFTA)',
                'list_type'      => 'negative',
                'status'         => 'listed_excluded',
                'note'           => \Ptad\Api\GuidanceEngine::saftaExcludedNote(),
                'members'        => $excludedRows,
            ];
        }

        // NOT found on any member's sensitive list — per SAFTA's
        // documented inverted logic, this means the product IS
        // eligible for the default ceiling. This is a computed,
        // synthesized result, clearly labeled as such (never presented
        // as if it were a stored database row), consistent with the
        // Loader Spec's explicit instruction that eligibility for
        // non-listed lines is computed at QUERY time, not load time.
        return [
            'agreement_code' => 'SAFTA',
            'agreement_name' => 'South Asian Free Trade Area (SAFTA)',
            'list_type'      => 'negative',
            'status'         => 'eligible_computed',
            'note'           => \Ptad\Api\GuidanceEngine::saftaEligibleNote((float) $agreement['default_ceiling_pct']),
            'default_ceiling_pct' => (float) $agreement['default_ceiling_pct'],
        ];
    }

    /**
     * GET /api/search-description?q=rice
     *
     * Free-text search against product_desc, using the FULLTEXT
     * index already present on tariff_lines.product_desc (built
     * into the schema from Step 3 — ft_lines_desc). Complements
     * the HS-code search: per the Application Flow document, users
     * should be able to search by product name/description too,
     * not only by HS code — especially valuable for GSTP/PTN's
     * historical codes, where description is the PRIMARY reliable
     * search key (see GuidanceEngine's PreHS note).
     */
    public static function handleDescriptionSearch(): void
    {
        $rawQuery = $_GET['q'] ?? null;
        $searchText = Validator::searchText($rawQuery, 200);

        if ($searchText === null) {
            ApiResponse::error(
                'invalid_input',
                'A valid q query parameter is required (free text, max 200 characters).',
                400
            );
        }

        $limit = Validator::limit($_GET['limit'] ?? null, 50, 500);

        // Offset validated directly (not via positiveInt, which forces
        // a minimum of 1 — offset=0 is the valid default "first page").
        $rawOffset = $_GET['offset'] ?? null;
        $offset = ($rawOffset !== null && ctype_digit((string) $rawOffset))
            ? min((int) $rawOffset, 100000)
            : 0;

        $pdo = Connection::get();

        // Count total matches first (separate query, cheap relative to
        // the full-text match itself) so callers can page through
        // results — without this, there'd be no way to know how many
        // total matches exist beyond the current page.
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM tariff_lines tl
            WHERE MATCH(tl.product_desc) AGAINST(:query IN NATURAL LANGUAGE MODE)
        ");
        $countStmt->bindValue(':query', $searchText, PDO::PARAM_STR);
        $countStmt->execute();
        $totalMatches = (int) $countStmt->fetchColumn();

        // MySQL's natural language full-text search: MATCH()...AGAINST()
        // ranks results by relevance automatically (via the relevance
        // score in the SELECT), so the best matches come first without
        // any extra ORDER BY logic needed here.
        $sql = "
            SELECT
                a.code AS agreement_code,
                a.short_name AS agreement_name,
                a.type AS agreement_type,
                ic.name AS import_country,
                mc.name AS member_country,
                tl.hs_code_raw,
                tl.hs_code_norm,
                tl.product_desc,
                tl.mfn_value,
                tl.mfn_text,
                MATCH(tl.product_desc) AGAINST(:query IN NATURAL LANGUAGE MODE) AS relevance
            FROM tariff_lines tl
            JOIN agreements a ON a.id = tl.agreement_id
            LEFT JOIN countries ic ON ic.id = tl.import_country_id
            LEFT JOIN countries mc ON mc.id = tl.member_country_id
            WHERE MATCH(tl.product_desc) AGAINST(:query2 IN NATURAL LANGUAGE MODE)
            ORDER BY relevance DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':query', $searchText, PDO::PARAM_STR);
        $stmt->bindValue(':query2', $searchText, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $results = $stmt->fetchAll();

        ApiResponse::success($results, [
            'query'         => $searchText,
            'result_count'  => count($results),
            'total_matches' => $totalMatches,
            'limit'         => $limit,
            'offset'        => $offset,
            'has_more'      => ($offset + count($results)) < $totalMatches,
        ]);
    }

    /**
     * GET /api/compare?hs_code=0802.1200
     *
     * Comparison-table view for one HS code: reshapes the same
     * underlying search data into a simplified table sorted by best
     * (lowest) effective rate first — matching the Application Flow
     * document's worked "comparison table" example, where a user
     * wants to see at a glance which agreement offers the best deal
     * for a given product, rather than one long flat result list.
     */
    public static function handleCompare(): void
    {
        $rawHsCode = $_GET['hs_code'] ?? null;
        $hsCodeQuery = Validator::hsCodeQuery($rawHsCode);

        if ($hsCodeQuery === null) {
            ApiResponse::error(
                'invalid_input',
                'A valid hs_code query parameter is required (digits, dots, and spaces only, max 20 characters).',
                400
            );
        }

        $normalized = HsCode::normalize($hsCodeQuery);
        $prefix = $normalized['norm'];

        $pdo = Connection::get();

        $rawResults = self::searchNormalModules($pdo, $prefix, null);
        $safta = self::searchSafta($pdo, $prefix);

        $rows = [];
        foreach ($rawResults as $r) {
            $rows[] = [
                'agreement_code'  => $r['agreement_code'],
                'agreement_name'  => $r['agreement_name'],
                'agreement_type'  => $r['agreement_type'],
                'country'         => $r['import_country'] ?? $r['member_country'],
                'hs_code'         => $r['hs_code_raw'],
                'product_desc'    => $r['product_desc'],
                'mfn_rate'        => $r['mfn_text'],
                'preferential_rate' => $r['rate_text'],
                'effective_advalorem' => $r['effective_advalorem'] !== null ? (float) $r['effective_advalorem'] : null,
                'is_excluded'     => (bool) $r['is_excluded'],
                'top_guidance'    => $r['guidance']['z3_notes'][0]['text'] ?? null, // just the highest-priority note, not the full list, to keep the table compact
            ];
        }

        if ($safta !== null) {
            $rows[] = [
                'agreement_code'  => 'SAFTA',
                'agreement_name'  => $safta['agreement_name'],
                'agreement_type'  => 'RTA',
                'country'         => null,
                'hs_code'         => $hsCodeQuery,
                'product_desc'    => null,
                'mfn_rate'        => null,
                'preferential_rate' => null,
                // SAFTA's ceiling is a maximum POTENTIAL discount, not
                // a stored/comparable final rate the way every other
                // agreement's effective_advalorem is — it deliberately
                // stays null rather than fabricating a number just to
                // rank it well, matching the "eligible_computed" note's
                // own honesty about being computed, not measured.
                'effective_advalorem' => null,
                'is_excluded'     => $safta['status'] === 'listed_excluded',
                'top_guidance'    => $safta['note'],
            ];
        }

        // Sort by effective rate ascending (best/lowest rate first) —
        // rows with no numeric rate (e.g. text-only/excluded lines)
        // sort to the end, since they can't be meaningfully ranked
        // alongside a real percentage.
        usort($rows, function ($a, $b) {
            if ($a['effective_advalorem'] === null && $b['effective_advalorem'] === null) return 0;
            if ($a['effective_advalorem'] === null) return 1;
            if ($b['effective_advalorem'] === null) return -1;
            return $a['effective_advalorem'] <=> $b['effective_advalorem'];
        });

        ApiResponse::success($rows, [
            'query'  => $hsCodeQuery,
            'normalized' => $prefix,
            'row_count' => count($rows),
        ]);
    }
}
