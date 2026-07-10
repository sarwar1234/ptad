<?php

declare(strict_types=1);

namespace Ptad\Api;

use PDO;

/**
 * ============================================================
 * PTAD — Guidance Engine (v2 — full rebuild against frozen spec)
 * ============================================================
 * REBUILT from scratch against the actual documents after an
 * honest audit found the v1 engine used paraphrased wording and
 * ad-hoc trigger logic instead of the frozen, owner-approved
 * Template Library (Doc C) and exact field-based Trigger
 * Conditions (Doc D). This version implements the sentences
 * VERBATIM as required ("no wording discretion") and assigns
 * each to its documented zone/priority per Doc E.
 *
 * ZONES (Doc E §2): Z1 header, Z2 rates, Z3 line notes (the
 * ranked list this engine mainly produces), Z4 links, Z5
 * standing disclaimer. M1-M5 are the module-page equivalents —
 * handled separately by whatever renders a module page, since
 * this engine operates per tariff line/search result.
 *
 * PRIORITY (Doc E §4): 1=blocking, 2=verify, 3=rate-determining,
 * 4=orientation, 5=advisory. Group 0's standing form sits in Z5
 * always; its EMPHASIS form is elevated into Z3 rank-1-equivalent
 * on high-risk lines.
 *
 * HONEST LIMITATIONS (documented, not hidden):
 *   - C2 ("code not matched") cannot currently fire: it depends
 *     on tariff_lines.eligibility_matches, which is confirmed
 *     UNPOPULATED (NULL/false) across all 212,738 loaded rows —
 *     no loader ever implemented the "official list" reconciliation
 *     this field requires. This method is written to fire
 *     correctly THE MOMENT that data exists, but will not fire
 *     today.
 * ============================================================
 */
final class GuidanceEngine
{
    /**
     * Generates the ranked Z3 note list for one tariff line, plus the
     * Z5 standing disclaimer (always returned separately, since it's
     * a different zone, not part of the ranked Z3 list).
     *
     * @param array $tariffLine  Row shape from SearchController (tariff_lines + agreements fields flattened)
     * @param array|null $tariffRate  Row shape for the specific rate row shown (tariff_rates fields), or null
     * @return array{z3_notes: array, d0_full: string, d0_short: string, d0_micro: string, emphasis: bool}
     */
    public static function generate(array $tariffLine, ?array $tariffRate): array
    {
        $notes = [];

        // --- Group A: staging note (NOT a template — the line's own
        // authentic remarks, shown verbatim, per Doc D §4's explicit
        // owner-directed correction). Fires only when the agreement
        // is phased AND this line actually has a remark. ---
        if (($tariffLine['staging'] ?? 'none') !== 'none' && !empty($tariffLine['remarks'])) {
            $notes[] = self::note('A', 3, $tariffLine['remarks']);
        }

        // --- Group B: rate-state (B1-B4), mutually exclusive by definition ---
        $bNote = self::groupB($tariffLine, $tariffRate);
        if ($bNote !== null) {
            $notes[] = $bNote;
        }

        // --- Group C1: member not implementing ---
        $c1Note = self::groupC1($tariffLine);
        if ($c1Note !== null) {
            $notes[] = $c1Note;
        }

        // --- Group C2: code not matched — see class docblock; will
        // never fire today since eligibility_matches is unpopulated,
        // but implemented correctly for when that data exists. ---
        if (($tariffLine['eligibility_checked'] ?? false) && ($tariffLine['eligibility_matches'] ?? true) === false) {
            $notes[] = self::note('C2', 2,
                'This product code could not be automatically matched to the latest official tariff list; please confirm the current code.');
        }

        // --- Group D: quota (D1/D2) ---
        $dNote = self::groupD($tariffRate, $tariffLine);
        if ($dNote !== null) {
            $notes[] = $dNote;
        }

        // --- Group E1: MFN comparison caution — fires on ANY line
        // showing a real preferential margin (per Doc D: "does NOT
        // fire on lines that show no preference — B1/B2/B3 already
        // cover those"). ---
        if ($bNote === null && self::hasRealPreferentialMargin($tariffRate)) {
            $notes[] = self::note('E1', 5,
                "Before ordering, compare this preferential rate with the partner country's current normal (MFN) duty — over time the normal duty can fall below the preferential rate.");
        }

        // --- Group F1: statistical sub-line ---
        if (!empty($tariffLine['is_substatistical'])) {
            $notes[] = self::note('F1', 4,
                'This is a statistical sub-category of the tariff line above and carries the same duty; refer to the main line for the applicable rate.');
        }

        // --- Group G: GSP scheme-specific (G1-G3) ---
        $gNote = self::groupG($tariffLine);
        if ($gNote !== null) {
            $notes[] = $gNote;
        }

        // G4 (new, Gap A2 / SRS FR-20): flags when the coverage-rule
        // check disagrees with the line's own stated eligibility —
        // not part of the original frozen Groups 0-J, added per the
        // SRS's explicit "flag disagreement for staff review" requirement.
        $coverageCheck = $tariffLine['coverage_rule_check'] ?? null;
        if ($coverageCheck !== null && !empty($coverageCheck['disagreement'])) {
            $notes[] = self::note('G4', 1,
                "FOR STAFF REVIEW: this line's stated eligibility disagrees with the chapter-level coverage rule (matched: {$coverageCheck['matched_rule']}). Please verify against the source before relying on either.");
        }

        // --- Group H1: description-language note — ONLY the 3
        // confirmed local-language modules (Doc D §11: EAEU GSP,
        // Türkiye GSP, Switzerland GSP). Explicitly NOT the broader
        // set of "partial coverage" modules a previous version of
        // this engine wrongly conflated this with. ---
        if (self::isLocalLanguageModule($tariffLine['agreement_code'] ?? '')) {
            $notes[] = self::note('H1', 4,
                "Product names in this schedule may appear in the partner country's language. Where the description is unclear, identify your product by its HS code, which is the authoritative reference.");
        }

        // --- Group I1: pre-HS coding (PTN, GSTP) ---
        if (in_array($tariffLine['agreement_code'] ?? '', ['GSTP', 'PTN'], true)) {
            $notes[] = self::note('I1', 4,
                'This agreement predates the Harmonized System (HS). Products are identified by the original Tariff Item Number used when the agreement was concluded, which differs from modern HS codes. Match your product by its description and Tariff Item Number; where needed, consult the official agreement schedule linked in this module.');
        }

        // --- Group J1: per-member phasing (D-8) ---
        if (($tariffLine['agreement_code'] ?? '') === 'D8' && ($tariffRate['applies_year'] ?? null) !== null) {
            $notes[] = self::note('J1', 3,
                'For this member, the preferential tariff is phased over several years — select a year to see the applicable rate. (Some D-8 members apply a single preferential rate instead.)');
        }

        // --- Determine emphasis (Doc D Table 1: elevate disclaimer
        // when ANY of these risk conditions hold for the line). ---
        $emphasis = self::shouldEmphasize($tariffLine, $tariffRate);

        // --- Rank by priority (Doc E §4), de-duplicate by group ID. ---
        $seen = [];
        $deduped = [];
        foreach ($notes as $n) {
            if (isset($seen[$n['group']])) continue;
            $seen[$n['group']] = true;
            $deduped[] = $n;
        }
        usort($deduped, fn($a, $b) => $a['priority'] <=> $b['priority']);

        return [
            'z3_notes'      => $deduped,
            'd0_full'       => 'The tariff rates, rules of origin, origin procedures, documentation requirements, and all other information provided by PTAD are intended as general guidance only. They are compiled from official sources but may not reflect the most recent changes. PTAD is not a legal authority and does not provide legal, customs, or financial advice. Before making any commercial decision, users must verify the applicable tariff treatment, eligibility conditions, and procedures against the official legal texts and the verification links provided, and where appropriate seek professional advice. PTAD, the Trade Development Authority of Pakistan, and its contributors accept no liability for any loss arising from reliance on this information.',
            'd0_short'      => 'Guidance only — verify against official sources before any commercial decision. PTAD accepts no liability for reliance on this information.',
            'd0_micro'      => 'Guidance only — verify at source before ordering.',
            'emphasis'      => $emphasis,
        ];
    }

    private static function note(string $group, int $priority, string $text): array
    {
        return ['group' => $group, 'priority' => $priority, 'text' => $text];
    }

    private static function groupB(array $tariffLine, ?array $tariffRate): ?array
    {
        if (!empty($tariffLine['is_excluded']) || ($tariffRate['rate_kind'] ?? null) === 'excluded') {
            return self::note('B3', 1, 'Excluded under the sensitive list — no concession applies.');
        }

        $rateKind = $tariffRate['rate_kind'] ?? null;

        if (in_array($rateKind, ['unspecified', 'text_only'], true)) {
            return self::note('B2', 2, 'Preferential rate to be verified — see official source.');
        }

        $prefValue = $tariffRate['effective_advalorem'] ?? null;

        if ($rateKind === 'free' || ($prefValue !== null && (float) $prefValue == 0.0)) {
            return self::note('B4', 3, 'Duty-free under this arrangement.');
        }

        $baseValue = $tariffLine['mfn_value'] ?? null;
        if ($baseValue !== null && $prefValue !== null && abs((float) $baseValue - (float) $prefValue) < 0.0001) {
            return self::note('B1', 3, 'No preference margin (preferential rate equals the base rate).');
        }

        return null;
    }

    private static function groupC1(array $tariffLine): ?array
    {
        $status = $tariffLine['member_status'] ?? null;

        if ($status === null || $status === 'implemented') {
            return null;
        }

        return self::note('C1', 1, 'This member has not yet started applying the agreement; please verify with customs.');
    }

    /**
     * Parses {margin} and {quantity} directly out of the existing
     * quota_note text (populated by QuotaNoteExtractor, Gap #3) —
     * avoids a schema migration for separate structured columns,
     * while still filling the frozen sentence's placeholders from
     * real data, never a fabricated number.
     */
    private static function groupD(?array $tariffRate, array $tariffLine = []): ?array
    {
        $quotaNote = $tariffRate['quota_note'] ?? null;
        if ($quotaNote === null) {
            return null;
        }

        $seasonNote = $tariffRate['season_note'] ?? null;

        $rateKind = $tariffRate['rate_kind'] ?? null;

        $quantity = null;
        if (preg_match('/([\d,]+\s*(?:MT|pieces))/i', $quotaNote, $m)) {
            $quantity = $m[1];
        }
        $quantityText = $quantity ?? 'the amount specified in the remarks';

        // The margin percentage was confirmed to live in the line's
        // REMARKS text (e.g. "10.5% reduction up to 100,000 MT
        // annually"), not always inside quota_note itself (which may
        // only capture the quantity clause, e.g. "100,000 MT
        // annually.") — check both, preferring quota_note but falling
        // back to remarks so the real figure is found either way.
        $margin = null;
        if (preg_match('/(\d+(?:\.\d+)?)\s*%/', $quotaNote, $m)) {
            $margin = $m[1];
        } elseif (preg_match('/(\d+(?:\.\d+)?)\s*%/', $tariffLine['remarks'] ?? '', $m)) {
            $margin = $m[1];
        }

        // Gap A4: fold the seasonal split into the same sentence when
        // present (confirmed real case: Sri Lanka's basmati rice quota
        // is split "2/3 during June-July and 1/3 during Oct-Nov") —
        // season_note previously had zero visibility anywhere in the API.
        $seasonClause = $seasonNote !== null ? ", allocated {$seasonNote}" : '';

        if ($rateKind === 'free') {
            return self::note('D2', 3, "Duty-free within an annual quota of {$quantityText}{$seasonClause}; above the quota, the normal (MFN) rate applies.");
        }

        $marginText = $margin !== null ? "{$margin}%" : 'A';
        return self::note('D1', 3, "{$marginText} preference within an annual quota of {$quantityText}{$seasonClause}; above the quota, the normal (MFN) rate applies.");
    }

    private static function groupG(array $tariffLine): ?array
    {
        $code = $tariffLine['agreement_code'] ?? '';
        $isEaeu = in_array($code, ['GSP_RUS', 'GSP_ARM', 'GSP_BLR', 'GSP_KAZ', 'GSP_KGZ'], true);

        // Use the actual stored agreements.type field, not a guess
        // based on whether the CODE string happens to contain "GSP" —
        // confirmed by direct testing that this string-matching
        // approach silently misses real GSP modules whose code doesn't
        // literally contain "GSP" (e.g. AUS_ASTP, CAN_GPT).
        $isGsp = $isEaeu || ($tariffLine['agreement_type'] ?? null) === 'GSP';

        if (!$isGsp) {
            return null;
        }

        if ($isEaeu && str_contains($tariffLine['remarks'] ?? '', 'Eligible for Pakistan')) {
            return self::note('G1', 3, 'Eligible goods receive a 25% reduction, i.e. duty is charged at 75% of the normal (CCT) rate.');
        }

        if ($isEaeu && str_contains($tariffLine['remarks'] ?? '', 'Not eligible')) {
            return self::note('G2', 1, 'This product is not listed for preference under this GSP scheme; the normal rate applies.');
        }

        if (($tariffLine['coverage'] ?? null) === 'partial') {
            return self::note('G3', 2, "Preference subject to the scheme's live tariff measures; verify the current schedule before shipment.");
        }

        return null;
    }

    private static function isLocalLanguageModule(string $agreementCode): bool
    {
        $eaeuCodes = ['GSP_RUS', 'GSP_ARM', 'GSP_BLR', 'GSP_KAZ', 'GSP_KGZ'];
        return in_array($agreementCode, $eaeuCodes, true)
            || $agreementCode === 'TUR_GSP'
            || $agreementCode === 'CHE_GSP';
    }

    private static function hasRealPreferentialMargin(?array $tariffRate): bool
    {
        if ($tariffRate === null) return false;

        $kind = $tariffRate['rate_kind'] ?? null;
        return in_array($kind, ['ad_valorem', 'free', 'compound', 'mixed', 'specific'], true);
    }

    private static function shouldEmphasize(array $tariffLine, ?array $tariffRate): bool
    {
        $rateKind = $tariffRate['rate_kind'] ?? null;
        if (in_array($rateKind, ['unspecified', 'text_only'], true)) return true;
        if (($tariffLine['eligibility_matches'] ?? true) === false) return true;
        if (($tariffLine['member_status'] ?? 'implemented') !== 'implemented') return true;
        if (!empty($tariffRate['quota_note'] ?? null)) return true;
        if (($tariffLine['staging'] ?? 'none') !== 'none' && ($tariffRate['applies_year'] ?? null) === null) return true;

        return false;
    }

    /**
     * SAFTA's excluded case IS literally the frozen B3 sentence
     * (Doc D: B3 fires when is_excluded=TRUE, which is exactly
     * SAFTA's negative-list condition) — reused verbatim here rather
     * than inventing separate SAFTA-specific wording.
     */
    public static function saftaExcludedNote(): string
    {
        return 'Excluded under the sensitive list — no concession applies.';
    }

    /**
     * SAFTA's "not listed = eligible up to ceiling" case has NO
     * frozen-library equivalent (the Template Library's B/D/E groups
     * describe individual line rate-states, not SAFTA's specific
     * ceiling-based negative-list framing) — this remains
     * project-authored explanatory text, clearly distinct from the
     * frozen sentences used everywhere else in this engine.
     */
    public static function saftaEligibleNote(float $ceilingPct): string
    {
        return "This HS code was not found on any SAFTA member's sensitive/exclusion list. Under SAFTA's "
             . "negative-list design, this means it is eligible for a concession of up to {$ceilingPct} "
             . "percentage points below the MFN rate, subject to Rules of Origin and customs verification. "
             . "This figure is computed, not a stored rate — always verify against the latest official schedule.";
    }

    /**
     * Gap D (Document E Table 2 — Placement Map): computes the
     * MODULE-LEVEL (M2) guidance panel for an agreement page —
     * distinct from generate()'s per-line (Z3) notes. Per the
     * confirmed placement map, only 6 groups ALSO appear on the
     * module page, computed ONCE for the whole agreement, not per
     * tariff line: C1 (member not implementing), E1 (MFN caution,
     * shown once here rather than repeated per line), G1 (EAEU
     * 75%-of-CCT explanation), G3 (GSP verify-schedule caution),
     * H1 (language note, only the 3 confirmed modules), I1 (pre-HS
     * coding note, PTN/GSTP only).
     *
     * @param array $agreement Row from the agreements table
     * @param array $members Rows from agreement_members (with country name + status)
     * @return array{m2_notes: array, m5_disclaimer_short: string}
     */
    public static function generateModuleLevel(array $agreement, array $members): array
    {
        $notes = [];
        $code = $agreement['code'] ?? '';

        // C1 — any member not implementing (agreement-wide check,
        // not tied to a specific tariff line, since D-8's Egypt/
        // Nigeria have zero tariff_lines rows to attach a per-line
        // C1 to — this is exactly why C1 belongs on the module page).
        $nonImplementing = array_filter($members, fn($m) => ($m['member_status'] ?? 'implemented') !== 'implemented');
        if (!empty($nonImplementing)) {
            $names = implode(', ', array_column($nonImplementing, 'country'));
            $notes[] = self::note('C1', 1, "The following member(s) have not yet started applying this agreement: {$names}. Please verify with customs.");
        }

        // E1 — shown once here (not repeated per line as on search results).
        $notes[] = self::note('E1', 5,
            "Before ordering, compare preferential rates in this agreement with the partner country's current normal (MFN) duty — over time the normal duty can fall below the preferential rate.");

        // G1 — EAEU explanation, agreement-wide.
        $eaeuCodes = ['GSP_RUS', 'GSP_ARM', 'GSP_BLR', 'GSP_KAZ', 'GSP_KGZ'];
        if (in_array($code, $eaeuCodes, true)) {
            $notes[] = self::note('G1', 3, 'Eligible goods receive a 25% reduction, i.e. duty is charged at 75% of the normal (CCT) rate.');
        }

        // G3 — GSP verify-schedule, agreement-wide (partial coverage).
        if (($agreement['coverage'] ?? null) === 'partial') {
            $notes[] = self::note('G3', 2, "Preference subject to the scheme's live tariff measures; verify the current schedule before shipment.");
        }

        // H1 — language note, exactly the 3 confirmed modules.
        if (self::isLocalLanguageModule($code)) {
            $notes[] = self::note('H1', 4,
                "Product names in this schedule may appear in the partner country's language. Where the description is unclear, identify your product by its HS code, which is the authoritative reference.");
        }

        // I1 — pre-HS coding, PTN/GSTP only.
        if (in_array($code, ['GSTP', 'PTN'], true)) {
            $notes[] = self::note('I1', 4,
                'This agreement predates the Harmonized System (HS). Products are identified by the original Tariff Item Number used when the agreement was concluded, which differs from modern HS codes. Match your product by its description and Tariff Item Number; where needed, consult the official agreement schedule linked in this module.');
        }

        usort($notes, fn($a, $b) => $a['priority'] <=> $b['priority']);

        return [
            'm2_notes'             => $notes,
            'm5_disclaimer_short'  => 'Guidance only — verify against official sources before any commercial decision. PTAD accepts no liability for reliance on this information.',
        ];
    }
}
