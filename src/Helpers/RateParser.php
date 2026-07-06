<?php

declare(strict_types=1);

namespace Ptad\Helpers;

/**
 * ============================================================
 * PTAD — Rate Parser
 * ============================================================
 * Turns a rate cell exactly as it appears in a workbook
 * ("5%", "Free", "Free p/st", "Rs 50/kg", "MOP (50%)",
 * "5% + Rs 50/kg", blank, "verify externally"...) into the
 * three-part storage the schema expects:
 *
 *   - rate_kind : one of the schema's ENUM values
 *                 (ad_valorem/specific/compound/mixed/free/
 *                  excluded/text_only/unspecified)
 *   - rate_value: a clean number IF the cell is a plain
 *                 percentage, otherwise NULL
 *   - rate_text : the ORIGINAL text, always preserved verbatim
 *   - effective_advalorem: best-effort %-equivalent for ranking/
 *                 comparison across modules (e.g. Free -> 0).
 *                 NULL when no reasonable numeric equivalent
 *                 exists (e.g. a pure specific duty like "Rs 50/kg"
 *                 cannot be expressed as a percentage).
 *
 * GOLDEN RULE (per the Loader Specification): never fabricate a
 * number. If the cell isn't cleanly a percentage, rate_value and
 * effective_advalorem stay NULL and the original text is kept —
 * the caller/reviewer can always see exactly what was in the sheet.
 * ============================================================
 */
final class RateParser
{
    /**
     * Phrases that mean "explicitly not eligible / no concession here",
     * as distinct from "blank/unknown". Case-insensitive, matched after
     * trimming.
     */
    private const EXCLUDED_PHRASES = [
        'excluded',
        'not eligible',
        'not applicable',
        'n/a',
        'na',
        'no concession',
        'sensitive',
        'no gsp for pakistan',
    ];

    /**
     * Phrases meaning the source explicitly wants external verification
     * rather than stating a number (e.g. Türkiye GSP "verify in official
     * schedule"). These must NEVER be treated as a stated rate.
     */
    private const VERIFY_PHRASES = [
        'verify',
        'to be verified',
        'unreconciled',
        'check official',
        'refer to',
    ];

    /**
     * @param bool $decimalFractionConvention Set true ONLY for modules
     *   (confirmed so far: Mauritius PTA) whose workbook stores rates as
     *   raw decimal fractions of 100 (e.g. 0.3 meaning 30%) rather than
     *   the "30%" text every other module uses. When true, a bare
     *   number under 1 is multiplied by 100 before being treated as a
     *   percentage. Off by default so no other module's behaviour changes.
     * @return array{
     *   rate_kind: string,
     *   rate_value: ?float,
     *   rate_text: ?string,
     *   effective_advalorem: ?float
     * }
     */
    public static function parse(?string $rawCell, bool $decimalFractionConvention = false): array
    {
        $text = trim((string) $rawCell);

        // Normalize European/Turkish-locale comma decimals to periods
        // BEFORE any other check. Confirmed by direct inspection: the
        // Türkiye PTA workbook's AFL/RD and ACD columns use a comma as
        // the decimal separator (e.g. "11,5%", "0,00") rather than the
        // period used everywhere else in the project's source files.
        // Without this normalization, "11,5%" was being misread as if
        // "5%" were the whole number (regex matching only the fragment
        // after the comma), silently corrupting the value rather than
        // just leaving it unparsed — this fixes that at the source,
        // before the rest of the parsing logic ever sees the string.
        // Pattern requires digit-comma-digit immediately before a '%'
        // or end of string, so it won't misfire on an unrelated comma
        // used as a genuine list separator elsewhere in a cell.
        $text = preg_replace('/(\d),(\d+)(?=\s*%|\s*$)/', '$1.$2', $text);

        // --- Blank cell: genuinely unknown, not a stated zero. ---
        if ($text === '') {
            return self::result('unspecified', null, null, null);
        }

        $lower = mb_strtolower($text);

        // --- Explicit "verify externally" cases (Türkiye GSP etc.) ---
        foreach (self::VERIFY_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                return self::result('unspecified', null, $text, null);
            }
        }

        // --- Explicit exclusion / not-eligible cases ---
        foreach (self::EXCLUDED_PHRASES as $phrase) {
            if ($lower === $phrase || str_starts_with($lower, $phrase . ' ')) {
                return self::result('excluded', null, $text, null);
            }
        }

        // --- Free (duty-free), with or without a trailing qualifier
        //     like "Free p/st" (per statute / per set — a unit note). ---
        if (preg_match('/^free\b/i', $text)) {
            return self::result('free', 0.0, $text, 0.0);
        }

        // --- Compound: has BOTH a percentage AND a specific-duty part,
        //     joined by "+" (e.g. "5% + Rs 50/kg"). ---
        if (str_contains($text, '+') && self::hasPercent($text) && self::hasCurrencyOrUnit($text)) {
            $pct = self::extractFirstPercent($text);
            return self::result('compound', null, $text, $pct);
        }

        // --- Mixed: "X% or Y, whichever is higher/lower". ---
        if (preg_match('/\bor\b.*\bwhichever\b/i', $text)) {
            $pct = self::extractFirstPercent($text);
            return self::result('mixed', null, $text, $pct);
        }

        // --- Pure specific duty: a currency/unit rate with NO percent
        //     sign anywhere in the cell (e.g. "Rs 50/kg", "USD 0.30/kg"). ---
        if (!self::hasPercent($text) && self::hasCurrencyOrUnit($text)) {
            return self::result('specific', null, $text, null);
        }

        // --- Clean ad valorem: the ENTIRE cell is just a number + '%',
        //     nothing else (e.g. "5%", "5.5 %", "40"). This is the only
        //     case where we set rate_value, because it's unambiguous. ---
        if (preg_match('/^(\d+(?:\.\d+)?)\s*%?$/', $text, $m)) {
            $value = (float) $m[1];
            // Mauritius-style workbooks store 30% as the raw decimal 0.3
            // rather than the text "30%" every other module uses. Only
            // convert when the caller has explicitly opted in for this
            // module, and only when the value looks like a fraction
            // (i.e. under 1) rather than a whole-number cell that
            // genuinely means e.g. "0" (Free) in that convention too.
            if ($decimalFractionConvention && $value > 0 && $value < 1) {
                $value *= 100;
                $text .= ' (converted from decimal-fraction source format)';
            }
            return self::result('ad_valorem', $value, $text, $value);
        }

        // --- Anything else with a percent sign somewhere, but not a
        //     clean standalone number (e.g. "MOP (50%)", "0% where
        //     eligible under GSP+"): keep the text, best-effort extract
        //     a %-equivalent for ranking, but do NOT set rate_value —
        //     the cell says more than a plain number and rate_value
        //     must not silently drop that context. ---
        if (self::hasPercent($text)) {
            $pct = self::extractFirstPercent($text);
            return self::result('text_only', null, $text, $pct);
        }

        // --- Fallback: some other descriptive text we don't recognise
        //     a pattern for. Preserve verbatim, no fabricated number. ---
        return self::result('text_only', null, $text, null);
    }

    private static function result(
        string $kind,
        ?float $value,
        ?string $text,
        ?float $effective
    ): array {
        return [
            'rate_kind'            => $kind,
            'rate_value'           => $value,
            'rate_text'            => $text,
            'effective_advalorem'  => $effective,
        ];
    }

    private static function hasPercent(string $text): bool
    {
        return str_contains($text, '%');
    }

    private static function hasCurrencyOrUnit(string $text): bool
    {
        // Common specific-duty markers seen across modules: currency
        // codes/symbols, or a "per unit" slash (e.g. "/kg", "/st", "p/st").
        // CHF added after inspecting Switzerland/Liechtenstein GSP, which
        // states specific duties exclusively in Swiss Francs.
        return (bool) preg_match('/(Rs\.?|PKR|USD|\$|EUR|€|CHF|p\/st|\/\s*(kg|ton|mt|litre|liter|unit|dozen|pair))/i', $text);
    }

    private static function extractFirstPercent(string $text): ?float
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*%/', $text, $m)) {
            return (float) $m[1];
        }
        return null;
    }
}
