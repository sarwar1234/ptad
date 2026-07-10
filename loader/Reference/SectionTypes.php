<?php

declare(strict_types=1);

namespace PtadLoader\Reference;

/**
 * ============================================================
 * PTAD — Section Types Reference Data
 * ============================================================
 * Built from a frequency count of every visible, non-tariff,
 * non-Home/Profile sheet name across all 29 workbooks (confirmed
 * by direct inspection, not assumed).
 *
 * Same duplicate-naming problem as Countries.php: the SAME kind
 * of section is spelled differently across modules (e.g.
 * "RULES OF ORIGIN" vs "Rules of Origin" vs "Rules of Origin &
 * Compliance" vs "ROO & Compliance" — all four are the same
 * concept). ALIASES maps every variant to one canonical code so
 * content_sections.section_type_id always points at ONE row per
 * real section kind, never a duplicate.
 *
 * "typical_order" gives a sensible default display order (matches
 * the order these sections consistently appear in the workbooks:
 * profile/overview -> tariff/concession summary -> rules of origin
 * -> procedures -> documentation -> market insights -> sources ->
 * technical guidance -> links/notes).
 * ============================================================
 */
final class SectionTypes
{
    /**
     * canonical_code => [display_name, description, typical_order]
     */
    public const DATA = [
        'agreement_profile' => [
            'Agreement Profile',
            'High-level profile of the arrangement: parties, type, entry into force, scope.',
            10,
        ],
        'member_overview' => [
            'Member Overview',
            'For multi-country arrangements: list of member countries and their status.',
            20,
        ],
        'concession_summary' => [
            'Concession Summary',
            'Summary of the tariff concessions/preferences granted under the arrangement.',
            30,
        ],
        'eligible_goods_list' => [
            'Eligible Goods List',
            'Chapter/heading-level eligibility rules with carve-outs (feeds coverage_rules table).',
            35,
        ],
        'rules_of_origin' => [
            'Rules of Origin',
            'The origin criteria a product must meet to qualify for preferential treatment.',
            40,
        ],
        'origin_procedures' => [
            'Origin Procedures',
            'Procedural steps for claiming and certifying origin (e.g. certificate of origin process).',
            50,
        ],
        'customs_admin_procedures' => [
            'Customs Administrative Procedures',
            'Customs-side administrative steps and requirements at the border.',
            55,
        ],
        'compliance_requirements' => [
            'Compliance Requirements',
            'Additional regulatory/compliance requirements beyond origin (e.g. standards, SPS).',
            60,
        ],
        'documentation_checklist' => [
            'Documentation Checklist',
            'The list of documents an exporter/importer needs to claim the preference.',
            70,
        ],
        'market_access_insights' => [
            'Market Access Insights',
            'Practical/market-context notes on using the arrangement (trends, tips, caveats).',
            80,
        ],
        'market_profile' => [
            'Market Profile',
            'Profile of the partner market relevant to trade under this arrangement.',
            85,
        ],
        'source_documents' => [
            'Source Documents',
            'Citations/references to the official legal texts and schedules used to build this module.',
            90,
        ],
        'technical_guidance' => [
            'Technical Guidance',
            'Technical notes for using/interpreting the module (definitions, caveats, methodology).',
            100,
        ],
        'verification_links' => [
            'Verification / Official Links',
            'Official government/agency links for live verification (feeds verification_links table).',
            110,
        ],
        'user_notes_limitations' => [
            'User Notes & Limitations',
            'Explicit caveats about what the module does and does not cover.',
            120,
        ],
    ];

    /**
     * Every alternate sheet-name spelling found in ANY workbook,
     * mapped to the canonical code (key in self::DATA) it represents.
     * Counts in comments are how many of the 29 files use that exact
     * spelling, confirmed by direct inspection.
     */
    public const ALIASES = [
        // agreement_profile (5 files: "AGREEMENT PROFILE")
        'AGREEMENT PROFILE'            => 'agreement_profile',
        'Scheme Profile'               => 'agreement_profile', // 7 files (GSP modules use this instead)
        'Home - User Guide'            => 'agreement_profile', // 7 files
        'Home User Guide'              => 'agreement_profile', // 1 file
        'Home_User_Guide'              => 'agreement_profile', // 5 files

        // member_overview (5 files, multi-country modules only)
        'MEMBER OVERVIEW'              => 'member_overview',
        'Status - Egypt'                => 'member_overview', // D-8 non-implementer note
        'Status - Nigeria'              => 'member_overview', // D-8 non-implementer note

        // concession_summary (5 files)
        'CONCESSION SUMMARY'           => 'concession_summary',

        // eligible_goods_list (5 files - EAEU/rule-based GSP modules)
        'Eligible Goods List'          => 'eligible_goods_list',

        // rules_of_origin (5 + 22 + 1 + 1 = 29 files total across all spellings)
        'RULES OF ORIGIN'              => 'rules_of_origin',
        'Rules of Origin & Compliance' => 'rules_of_origin',
        'ROO & Compliance'             => 'rules_of_origin',

        // origin_procedures (5 + 9 files)
        'ORIGIN PROCEDURES'            => 'origin_procedures',

        // customs_admin_procedures (4 files - SAFTA-family only)
        'CUSTOMS_ADMIN_PROCEDURES'     => 'customs_admin_procedures',

        // compliance_requirements (5 + 8 files)
        'Compliance Req.'              => 'compliance_requirements',

        // documentation_checklist (5 + 18 + 5 files)
        'DOCUMENTATION CHECKLIST'      => 'documentation_checklist',
        'Documentation'                => 'documentation_checklist',

        // market_access_insights (4 + 17 files)
        'MARKET ACCESS INSIGHTS'       => 'market_access_insights',
        'Market Insights'              => 'market_access_insights',

        // market_profile (5 files)
        'Market Profile'               => 'market_profile',

        // source_documents (5 + 23 files)
        'SOURCE DOCUMENTS'             => 'source_documents',

        // technical_guidance (4 + 22 files)
        'TECHNICAL GUIDANCE'           => 'technical_guidance',

        // verification_links (one per granting country, single-file each)
        'Canada Links'                 => 'verification_links',
        'Tariff Verification Links'    => 'verification_links', // New Zealand's GSP module - found during content sections loader testing
        'EU Links'                     => 'verification_links',
        'Norway Links'                 => 'verification_links',
        'Türkiye Links'                => 'verification_links',
        'UK Trade Tools'               => 'verification_links',
        'Online Links'                 => 'verification_links',

        // user_notes_limitations (1 file - Australia's hybrid caveat sheet)
        'USER NOTES & LIMITATIONS'     => 'user_notes_limitations',
    ];

    public static function resolveCanonicalCode(string $sheetName): ?string
    {
        $name = trim($sheetName);

        // Exact alias match first.
        if (isset(self::ALIASES[$name])) {
            return self::ALIASES[$name];
        }

        // Exact match against a canonical display name (e.g. "Rules of Origin").
        foreach (self::DATA as $code => [$displayName]) {
            if (strcasecmp($displayName, $name) === 0) {
                return $code;
            }
        }

        // No match: return null rather than guessing. The loader logs
        // this as an exception-report entry (per Loader Spec B9) instead
        // of silently dropping the section or fabricating a new type.
        return null;
    }
}
