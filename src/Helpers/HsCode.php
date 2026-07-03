<?php

declare(strict_types=1);

namespace Ptad\Helpers;

/**
 * ============================================================
 * PTAD — HS Code Normalization
 * ============================================================
 * Turns an HS code exactly as it appears in a workbook cell
 * (dots, spaces, sometimes stray letters) into:
 *   - hs_code_norm : digits only, for fast/consistent search
 *   - hs_digits    : how many digits (4/6/8/10/12)
 *   - hs6          : first 6 normalised digits (the "HS root"),
 *                    used to roll up / compare across modules
 *                    that use different code lengths.
 *
 * The raw, original text is ALWAYS preserved separately by the
 * caller (hs_code_raw) — this class never discards the original.
 * ============================================================
 */
final class HsCode
{
    /**
     * @return array{raw: string, norm: string, digits: int, hs6: ?string}
     */
    public static function normalize(string $rawCode): array
    {
        $raw = trim($rawCode);

        // Strip everything except digits: removes dots, spaces, dashes,
        // and any stray letters (e.g. a trailing 'S' or footnote marker
        // that sometimes leaks into HS code columns).
        $norm = preg_replace('/[^0-9]/', '', $raw) ?? '';

        $digits = strlen($norm);

        // HS6 root: only meaningful once we have at least 6 digits.
        // Modules with shorter/legacy codes (4-digit PTN/GSTP) get NULL
        // here rather than a padded/guessed value — we never fabricate
        // digits that weren't in the source.
        $hs6 = $digits >= 6 ? substr($norm, 0, 6) : null;

        return [
            'raw'    => $raw,
            'norm'   => $norm,
            'digits' => $digits,
            'hs6'    => $hs6,
        ];
    }

    /**
     * True if a normalized code looks like a usable HS code at all
     * (non-empty, and not something clearly wrong like a single digit
     * left over from a stray character). Used by the loader to decide
     * whether a row belongs in the exceptions report instead of being
     * silently inserted with garbage data.
     */
    public static function isPlausible(string $normCode): bool
    {
        $len = strlen($normCode);
        return $len >= 2 && $len <= 12;
    }
}
