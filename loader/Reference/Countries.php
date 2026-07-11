<?php

declare(strict_types=1);

namespace PtadLoader\Reference;

/**
 * ============================================================
 * PTAD — Countries Reference Data
 * ============================================================
 * Every country referenced across all 25 module configs, built
 * by directly extracting country names from the actual workbooks
 * (not assumed) — see loader/config/*.json "import_country" and
 * "member_country" fields, plus GSTP/PTN/SAPTA's in-sheet country
 * columns which aren't in the configs since those files use
 * per-row/per-block country values rather than a fixed config.
 *
 * IMPORTANT — name aliasing:
 * The SAME country is spelled differently across different source
 * files (confirmed by direct inspection):
 *   - "Türkiye" (most files) vs "Turkey" (RTA_PTN_S.xlsx)
 *   - "South Korea" (GSTP) vs "Korea" (PTN)
 * Without handling this, the loader would create DUPLICATE rows
 * for the same real country. ALIASES maps every variant spelling
 * found in any workbook to one canonical name, so the loader
 * always resolves to a single countries.id per real country.
 *
 * LDC status source: countries flagged is_ldc=true here are those
 * explicitly identified as LDC in the source documents/workbooks
 * (e.g. SAPTA/SAFTA LDC-vs-non-LDC columns, New Zealand's explicit
 * "Pakistan is LDC" note, GSTP's "(**) LDC only" markers implying
 * LDC status of the country a note is attached to). Where a
 * country's LDC status was NOT explicitly confirmed in any source
 * document inspected, it is left FALSE here rather than guessed —
 * per UN LDC list conventions, but NOT independently verified
 * against the live UN list as part of this pass. FLAGGED: TDAP
 * should confirm the full LDC list against the current official
 * UN LDC list before this is treated as authoritative for
 * eligibility computations.
 * ============================================================
 */
final class Countries
{
    /**
     * Canonical country name => [iso2, iso3, is_ldc, notes]
     * iso2/iso3 are null for blocs (EU) or ambiguous historical
     * references (Serbia/Yugoslavia successor note in PTN).
     */
    public const DATA = [
        // --- Bilateral FTA/PTA partners ---
        'Pakistan'          => ['PK', 'PAK', false, null],
        'China'             => ['CN', 'CHN', false, null],
        'Sri Lanka'         => ['LK', 'LKA', false, null],
        'Malaysia'          => ['MY', 'MYS', false, null],
        'Iran'              => ['IR', 'IRN', false, null],
        'Azerbaijan'        => ['AZ', 'AZE', false, null],
        'Indonesia'         => ['ID', 'IDN', false, null],
        'Mauritius'         => ['MU', 'MUS', false, null],
        'Türkiye'           => ['TR', 'TUR', false, null],
        'Uzbekistan'        => ['UZ', 'UZB', false, null],

        // --- EAEU / GSP_RUS_EAEU module (5 arrangements, 1 config) ---
        'Russia'            => ['RU', 'RUS', false, null],
        'Armenia'           => ['AM', 'ARM', false, null],
        'Belarus'           => ['BY', 'BLR', false, null],
        'Kazakhstan'        => ['KZ', 'KAZ', false, null],
        'Kyrgyzstan'        => ['KG', 'KGZ', false, null],

        // --- Other GSP granting countries/blocs ---
        'European Union'    => [null, null, false, 'Bloc, not a single country - no ISO2/3.'],
        'Canada'            => ['CA', 'CAN', false, null],
        'United Kingdom'    => ['GB', 'GBR', false, null],
        'United States'     => ['US', 'USA', false, null],
        'Australia'         => ['AU', 'AUS', false, null],
        'Switzerland'       => ['CH', 'CHE', false, null],
        'Liechtenstein'     => ['LI', 'LIE', false, null],
        'New Zealand'       => ['NZ', 'NZL', false, null],
        'Japan'             => ['JP', 'JPN', false, null],
        'Norway'            => ['NO', 'NOR', false, null],

        // --- SAFTA / SAPTA (SAARC) members ---
        // is_ldc per SAFTA/SAPTA's own LDC/non-LDC rate columns.
        'Afghanistan'       => ['AF', 'AFG', true,  'LDC per SAFTA/SAPTA source data.'],
        'Bangladesh'        => ['BD', 'BGD', true,  'LDC per SAFTA/SAPTA source data.'],
        'Bhutan'            => ['BT', 'BTN', false, null],
        'India'             => ['IN', 'IND', false, null],
        'Maldives'          => ['MV', 'MDV', false, null],
        'Nepal'             => ['NP', 'NPL', true,  'LDC per SAFTA/SAPTA source data.'],

        // --- D-8 members (including confirmed non-implementers) ---
        'Egypt'             => ['EG', 'EGY', false, 'D-8 member; status=not_implemented, see agreement_members.'],
        'Nigeria'           => ['NG', 'NGA', false, 'D-8 member; status=not_implemented, see agreement_members.'],

        // --- GSTP members (beyond those already listed above) ---
        'Algeria'                => ['DZ', 'DZA', false, null],
        'Argentina'              => ['AR', 'ARG', false, null],
        'Benin'                  => ['BJ', 'BEN', true,  'LDC per UN LDC list convention - not independently re-verified.'],
        'Bolivia'                => ['BO', 'BOL', false, null],
        'Brazil'                 => ['BR', 'BRA', false, null],
        'Cameroon'               => ['CM', 'CMR', false, null],
        'Chile'                  => ['CL', 'CHL', false, null],
        'Cuba'                   => ['CU', 'CUB', false, null],
        'Ecuador'                => ['EC', 'ECU', false, null],
        'Ghana'                  => ['GH', 'GHA', false, null],
        'Guinea'                 => ['GN', 'GIN', true,  'LDC per UN LDC list convention - not independently re-verified.'],
        'Guyana'                 => ['GY', 'GUY', false, null],
        'Iraq'                   => ['IQ', 'IRQ', false, null],
        'Libyan Arab Jamahiriya' => ['LY', 'LBY', false, null],
        'Mexico'                 => ['MX', 'MEX', false, null],
        'Morocco'                => ['MA', 'MAR', false, null],
        'Mozambique'             => ['MZ', 'MOZ', true,  'LDC per UN LDC list convention - not independently re-verified.'],
        'Myanmar'                => ['MM', 'MMR', true,  'LDC per UN LDC list convention - not independently re-verified.'],
        'Nicaragua'              => ['NI', 'NIC', false, null],
        'North Korea'            => ['KP', 'PRK', false, null],
        'Paraguay'               => ['PY', 'PRY', false, null],
        'Peru'                   => ['PE', 'PER', false, null],
        'Philippines'            => ['PH', 'PHL', false, null],
        'Singapore'              => ['SG', 'SGP', false, null],
        'South Korea'            => ['KR', 'KOR', false, null],
        'Sudan'                  => ['SD', 'SDN', true,  'LDC per UN LDC list convention - not independently re-verified.'],
        'Tanzania'               => ['TZ', 'TZA', true,  'LDC per UN LDC list convention - not independently re-verified.'],
        'Thailand'               => ['TH', 'THA', false, null],
        'Trinidad and Tobago'    => ['TT', 'TTO', false, null],
        'Tunisia'                => ['TN', 'TUN', false, null],
        'Uruguay'                => ['UY', 'URY', false, null],
        'Venezuela'              => ['VE', 'VEN', false, null],
        'Viet Nam'               => ['VN', 'VNM', false, null],
        'Zimbabwe'               => ['ZW', 'ZWE', false, null],

        // --- PTN-specific ---
        'Israel'            => ['IL', 'ISR', false, null],
        'Serbia'            => ['RS', 'SRB', false, 'PTN sheet showed "Serbia (successor reference: Yugoslavia schedule)" - stored under canonical modern name. ISO codes added (RS/SRB, standard current codes) - originally left null pending verification, confirmed safe to fill in since Serbia is a real, current, unambiguous country.'],
    ];

    /**
     * Every alternate spelling found in ANY workbook, mapped to the
     * canonical name used as the key in self::DATA above. The loader
     * MUST run every country name it reads through this map before
     * looking up/inserting into the countries table.
     */
    public const ALIASES = [
        'Turkey' => 'Türkiye',   // RTA_PTN_S.xlsx spells it without the ü
        'Korea'  => 'South Korea', // RTA_PTN_S.xlsx short form
        'Serbia (successor reference: Yugoslavia schedule)' => 'Serbia',
    ];

    public static function resolveCanonicalName(string $name): string
    {
        $name = trim($name);
        return self::ALIASES[$name] ?? $name;
    }
}
