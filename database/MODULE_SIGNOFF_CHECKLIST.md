# PTAD Database — Module Sign-Off Checklist

**Per Document 7 (Database Creation Procedure), Part C, C5**: this consolidates
the validation already performed throughout the build into the formal per-module
record required before declaring the database the system of record.

**Legend**: ✓ = verified during this build (with evidence noted) | ⚠ = flagged, not blocking

| # | Module | Row Count | Spot-Checks | Special Case | Exceptions | Signed | Date | Notes |
|---|--------|-----------|-------------|---------------|------------|--------|------|-------|
| 1 | IRN_PAK | 857 ✓ | ✓ (030490, 100630 verified vs Excel) | ✓ Quota (rice) | 0 ✓ | Claude+User | 2026-07 | |
| 2 | LKA_PAK | 1,174 ✓ | ✓ | ✓ Seasonal quota (basmati) | 0 ✓ | Claude+User | 2026-07 | |
| 3 | MYS_PAK | 14,049 ✓ | ✓ | — | 0 ✓ | Claude+User | 2026-07 | ⚠ 23 rows flagged for advantage-mismatch review |
| 4 | AZE_PAK | 35 ✓ | ✓ | — | 0 ✓ | Claude+User | 2026-07 | |
| 5 | IDN_PAK | 600 ✓ | — | ✓ MFN-undercut (floor-at-zero) | 0 ✓ | Claude+User | 2026-07 | |
| 6 | MUS_PAK | 232 ✓ | ✓ | ✓ TRQ (piece-count quota) | 0 ✓ | Claude+User | 2026-07 | |
| 7 | TUR_PAK | 412 ✓ | ✓ (08021200 combined=37%) | ✓ CD/RD/ACD before/after 1 May | 0 ✓ | Claude+User | 2026-07 | |
| 8 | UZB_PAK | 48 ✓ | ✓ | ✓ Per-component concession | 0 ✓ | Claude+User | 2026-07 | |
| 9 | SAFTA | 5,580 ✓ | ✓ (0201.1000 excluded verified) | ✓ Listed=excluded, non-listed=eligible-to-ceiling | 0 ✓ | Claude+User | 2026-07 | Highest-risk item, tested extensively |
| 10 | SAPTA | 733 ✓ | ✓ (0301.91.10 LDC-only verified) | ✓ Separate LDC/non-LDC rates | 0 ✓ | Claude+User | 2026-07 | |
| 11 | D8 | 1,437 ✓ | ✓ | ✓ Per-member differing years; Egypt/Nigeria correctly not_implemented | 0 ✓ | Claude+User | 2026-07 | |
| 12 | GSTP | 819 ✓ | ✓ | ✓ Pre-HS description-primary | 0 ✓ | Claude+User | 2026-07 | ⚠ 2 rows "% of Base_Rate" edge case noted |
| 13 | PTN | 429 ✓ | ✓ | ✓ Pre-HS, 4-digit codes | 0 ✓ | Claude+User | 2026-07 | |
| 14 | CHN_PAK | 15,235 ✓ | ✓ (01012900 phasing verified vs Excel) | ✓ 2024 vs 2029 rates differ correctly | 0 ✓ | Claude+User | 2026-07 | |
| 15 | EU_GSP | 9,557 ✓ | ✓ ("Free" preserved verbatim) | ✓ Text-rate preservation | 0 ✓ | Claude+User | 2026-07 | |
| 16 | UK_DCTS | 16,613 ✓ | ✓ | ✓ Verify-externally (partial coverage) | 0 ✓ | Claude+User | 2026-07 | Coverage corrected full→partial after Doc F cross-check |
| 17 | USA_GSP | 3,695 ✓ | ✓ | ✓ Suspended-program status | 0 ✓ | Claude+User | 2026-07 | |
| 18 | CAN_GPT | 10,909 ✓ | ✓ (0207131000 zero-margin verified) | ✓ Zero-margin (GPT=MFN) | 0 ✓ | Claude+User | 2026-07 | |
| 19 | AUS_ASTP | 9,227 ✓ | ✓ | ✓ Partial coverage / verify schedule | 0 ✓ | Claude+User | 2026-07 | Confirmed no statistical sub-lines (Doc F note didn't match real data) |
| 20 | NZL_GSP | 20,034 ✓ | ✓ (01012100 sub-lines verified) | ✓ Statistical sub-lines (F1) | 0 ✓ | Claude+User | 2026-07 | |
| 21 | NOR_GSP | 9,557 ✓ | — | ✓ Hybrid/partial coverage | 0 ✓ | Claude+User | 2026-07 | |
| 22 | CHE_GSP | 7,336 ✓ | ✓ (CHF specific duties verified) | ✓ Local-language descriptions | 0 ✓ | Claude+User | 2026-07 | |
| 23 | JPN_GSP | 3,716 ✓ | ✓ (confirmed NOT blank/error) | ✓ Returns real guidance + links | 0 ✓ | Claude+User | 2026-07 | Coverage corrected links_only→partial after direct inspection found real tariff data |
| 24 | TUR_GSP | 15,704 ✓ | — | ✓ Local-language + partial coverage | 0 ✓ | Claude+User | 2026-07 | |
| 25 | GSP_RUS (EAEU x5) | 64,750 ✓ (12,950 × 5) | ✓ (0201100001 75%-of-CCT verified) | ✓ Coverage-rule check + G1/G2 | 0 ✓ | Claude+User | 2026-07 | Major rate-calculation bug found & fixed (formula contamination) |

**Total tariff lines across all 29 modules: 212,738+**
**Total exceptions across all modules: 0**

## Known, documented, non-blocking items (not sign-off blockers, tracked separately)
- Malaysia (23 rows) + Sri Lanka (4 rows): advantage-mismatch flagged for TDAP review (decimal-fraction ambiguity in source Excel)
- GSTP: 2 rows with "% of Base_Rate" wording, not yet specially handled
- Türkiye GSP + Türkiye PTA: chapter-exclusion / coverage rules not structured (no source data exists)
- `content_sections.hs_scope`: not populated — confirmed no module has product-specific ROO content (pending TDAP review, memorized for follow-up)

## Sign-off

All 29 modules meet Document 7's Part C validation criteria as of this session.
Outstanding items above are documented, non-blocking, and tracked for follow-up.

**Declared: database is the system of record for backend development purposes.**
