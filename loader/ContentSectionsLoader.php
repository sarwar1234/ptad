<?php

declare(strict_types=1);

namespace PtadLoader;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Ptad\Database\Connection;
use PtadLoader\Reference\SectionTypes;
use PtadLoader\Support\ExceptionsLog;

/**
 * ============================================================
 * PTAD — Content Sections Loader
 * ============================================================
 * Loads the non-tariff sections (Rules of Origin, Documentation
 * Checklist, Market Access Insights, Source Documents, Technical
 * Guidance, etc.) into content_sections, and — for the
 * "Source Documents"-style sheets that have a URL column — into
 * verification_links as well.
 *
 * This was flagged as a genuine gap during Phase 3 API testing:
 * the Loader Spec always intended this (Doc 4 §B5/B6), but the
 * Phase 2 loaders only ever covered tariff data.
 *
 * HEADER ROW AUTO-DETECTION: confirmed by direct inspection that
 * different sheets (even within the same workbook) have their
 * real header row at a different position — some have a "caution"
 * text row before the header, some don't (e.g. Iran's Rules of
 * Origin header is row 5, but Documentation Checklist's is row 4).
 * Rather than hardcode a row number per sheet per module (29
 * modules x ~10 section types = too fragile), this loader scans
 * for the header row: the first row with 3+ non-empty cells that
 * is immediately followed by another row also with 3+ non-empty
 * cells (a real header is followed by real data, not by another
 * title/blank row).
 *
 * Every column beyond the first two (title, body) is captured
 * into the flexible `fields` JSON column, per schema Section 8's
 * design for exactly this variability across modules — nothing
 * is discarded even where a sheet's exact column layout differs.
 * ============================================================
 */
final class ContentSectionsLoader
{
    private PDO $pdo;
    private ExceptionsLog $exceptions;

    /**
     * Every plausible section sheet name found across all 29
     * workbooks (built from the same frequency scan done in Step 6
     * for SectionTypes — every alias key, since sheet names vary by
     * module family).
     */
    private const CANDIDATE_SHEET_NAMES = [
        'AGREEMENT PROFILE', 'Scheme Profile', 'Home - User Guide', 'Home User Guide', 'Home_User_Guide',
        'MEMBER OVERVIEW', 'Status - Egypt', 'Status - Nigeria',
        'CONCESSION SUMMARY',
        'Eligible Goods List',
        'RULES OF ORIGIN', 'Rules of Origin', 'Rules of Origin & Compliance', 'ROO & Compliance',
        'ORIGIN PROCEDURES', 'Origin Procedures',
        'CUSTOMS_ADMIN_PROCEDURES',
        'Compliance Req.', 'Compliance Requirements',
        'DOCUMENTATION CHECKLIST', 'Documentation', 'Documentation Checklist',
        'MARKET ACCESS INSIGHTS', 'Market Insights', 'Market Access Insights',
        'Market Profile',
        'SOURCE DOCUMENTS', 'Source Documents',
        'TECHNICAL GUIDANCE', 'Technical Guidance',
        'Canada Links', 'EU Links', 'Norway Links', 'Türkiye Links', 'UK Trade Tools', 'Online Links',
        'Tariff Verification Links',
        'USER NOTES & LIMITATIONS',
    ];

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->exceptions = new ExceptionsLog('content_sections');
    }

    /**
     * Loads content sections for one module. Returns counts for
     * reporting; never throws for a missing/unreadable sheet (that's
     * expected — not every module has every section type) but does
     * log genuinely unexpected errors to the exceptions log.
     */
    public function loadForModule(string $moduleCode, string $sourceWorkbook, int $agreementId): array
    {
        $filePath = __DIR__ . '/../data/AGREEMENT_MODULES_29/' . $sourceWorkbook;

        if (!file_exists($filePath)) {
            throw new \RuntimeException("Workbook not found: {$filePath}");
        }

        // Wipe this agreement's existing sections/links first, per the
        // same re-runnable-per-module design as every tariff handler.
        $this->pdo->prepare("DELETE FROM content_sections WHERE agreement_id = :id")->execute([':id' => $agreementId]);
        $this->pdo->prepare("DELETE FROM verification_links WHERE agreement_id = :id")->execute([':id' => $agreementId]);

        $spreadsheet = IOFactory::createReaderForFile($filePath)->load($filePath);

        $sectionsLoaded = 0;
        $linksLoaded = 0;

        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            $canonicalCode = SectionTypes::resolveCanonicalCode($sheetName);

            if ($canonicalCode === null) {
                continue; // not a recognized content-section sheet (e.g. it's a tariff sheet, Home, Country Profile)
            }

            $sheet = $spreadsheet->getSheetByName($sheetName);
            $headerRow = $this->detectHeaderRow($sheet);

            if ($headerRow === null) {
                $this->exceptions->record($moduleCode, 0, '', "Could not auto-detect a header row in sheet '{$sheetName}'.");
                continue;
            }

            $headers = $this->readRow($sheet, $headerRow);
            $highestRow = $sheet->getHighestRow();

            $sectionTypeId = $this->getSectionTypeId($canonicalCode);
            $isLinkSheet = $this->looksLikeLinkSheet($headers);

            $rowOrder = 0;
            for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
                $values = $this->readRow($sheet, $row);

                if (self::allBlank($values)) {
                    continue;
                }

                if ($isLinkSheet) {
                    $linksLoaded += $this->insertAsLink($agreementId, $headers, $values);
                } else {
                    $sectionsLoaded += $this->insertAsSection($agreementId, $sectionTypeId, ++$rowOrder, $headers, $values);
                }
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        gc_collect_cycles();

        return ['sections_loaded' => $sectionsLoaded, 'links_loaded' => $linksLoaded];
    }

    public function closeLog(): void
    {
        $this->exceptions->close();
    }

    /**
     * Scans the first 12 rows for the header: the first row with 3+
     * non-empty cells that is immediately followed by another row
     * also with 3+ non-empty cells (a real header is followed by
     * real data — a lone title row isn't).
     */
    private function detectHeaderRow($sheet): ?int
    {
        $highestRow = $sheet->getHighestRow();
        $maxScan = min(12, $highestRow);

        for ($row = 1; $row <= $maxScan; $row++) {
            // A header row MUST have at least one real data row after
            // it — a match right at (or past) the sheet's last row is
            // a false positive (e.g. two footer/note lines that both
            // happen to have 3+ cells), not an actual header.
            if ($row >= $highestRow) {
                break;
            }

            $current = $this->readRow($sheet, $row);
            $next = $this->readRow($sheet, $row + 1);

            if (self::nonEmptyCount($current) >= 2 && self::nonEmptyCount($next) >= 2) {
                return $row;
            }
        }

        return null;
    }

    private function readRow($sheet, int $row): array
    {
        // Defensive bounds check: PhpSpreadsheet throws if asked for a
        // row past the sheet's actual highest row, rather than simply
        // returning empty — confirmed by direct testing against several
        // short reference-style sheets in the real workbooks.
        if ($row < 1 || $row > $sheet->getHighestRow()) {
            return [];
        }

        $values = [];
        $highestCol = $sheet->getHighestColumn();
        foreach ($sheet->getRowIterator($row, $row)->current()->getCellIterator('A', $highestCol) as $cell) {
            $values[$cell->getColumn()] = trim((string) $cell->getValue());
        }
        return $values;
    }

    private static function nonEmptyCount(array $values): int
    {
        return count(array_filter($values, fn($v) => $v !== ''));
    }

    private static function allBlank(array $values): bool
    {
        return self::nonEmptyCount($values) === 0;
    }

    /**
     * A sheet "looks like" a verification_links source if its header
     * row contains a column whose label suggests a URL (confirmed
     * real headers: "URL", "Link", "Open"). Otherwise treated as a
     * regular content_sections sheet.
     */
    private function looksLikeLinkSheet(array $headers): bool
    {
        foreach ($headers as $h) {
            if (preg_match('/\b(URL|Link)\b/i', $h)) {
                return true;
            }
        }
        return false;
    }

    private function insertAsSection(int $agreementId, int $sectionTypeId, int $rowOrder, array $headers, array $values): int
    {
        $cols = array_keys($headers);
        $firstCol = $cols[0] ?? null;
        $secondCol = $cols[1] ?? null;

        $title = $firstCol !== null ? ($values[$firstCol] ?? null) : null;
        $body = $secondCol !== null ? ($values[$secondCol] ?? null) : null;

        // Every OTHER column (3rd onward) goes into the flexible
        // `fields` JSON — labeled with its real header — per schema
        // Section 8's design, so no column's data is ever discarded
        // just because it doesn't fit the title/body shape.
        $extraFields = [];
        foreach ($cols as $i => $col) {
            if ($i < 2) {
                continue;
            }
            $label = $headers[$col] ?: $col;
            $value = $values[$col] ?? '';
            if ($value !== '') {
                $extraFields[$label] = $value;
            }
        }

        // A source-reference-looking column (label contains "Source"
        // or "Reference") is pulled out into the dedicated source_ref
        // field too, in addition to staying in fields for completeness.
        $sourceRef = null;
        foreach ($cols as $col) {
            if (preg_match('/source|reference/i', $headers[$col] ?? '')) {
                $sourceRef = $values[$col] ?: null;
                break;
            }
        }

        if ($title === null && $body === null) {
            return 0; // nothing meaningful to store
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO content_sections
                (agreement_id, section_type_id, row_order, title, body, fields, source_ref)
            VALUES
                (:agreement_id, :section_type_id, :row_order, :title, :body, :fields, :source_ref)
        ");
        $stmt->execute([
            ':agreement_id'    => $agreementId,
            ':section_type_id' => $sectionTypeId,
            ':row_order'       => $rowOrder,
            ':title'           => $title !== '' ? $title : null,
            ':body'            => $body !== '' ? $body : null,
            ':fields'          => !empty($extraFields) ? json_encode($extraFields, JSON_UNESCAPED_UNICODE) : null,
            ':source_ref'      => $sourceRef,
        ]);

        return 1;
    }

    private function insertAsLink(int $agreementId, array $headers, array $values): int
    {
        $cols = array_keys($headers);

        $urlCol = null;
        $nameCol = null;
        $purposeCol = null;
        $sourceCol = null;

        foreach ($cols as $col) {
            $label = $headers[$col] ?? '';
            if ($urlCol === null && preg_match('/\b(URL|Link)\b/i', $label)) {
                $urlCol = $col;
            } elseif ($nameCol === null && preg_match('/document|source|resource/i', $label)) {
                $nameCol = $col;
            } elseif ($purposeCol === null && preg_match('/used|purpose|how/i', $label)) {
                $purposeCol = $col;
            } elseif ($sourceCol === null && preg_match('/issuing|body|authority/i', $label)) {
                $sourceCol = $col;
            }
        }

        $url = $urlCol !== null ? ($values[$urlCol] ?? '') : '';

        // Some "URL" columns actually hold plain-text notes like
        // "Ministry of Commerce, Pakistan website" rather than a real
        // clickable link (confirmed in Iran's Source Documents sheet) —
        // only insert as a verification_links row when it looks like an
        // actual URL, otherwise it's still fully captured as a regular
        // content_sections row by the caller's fallback, so nothing
        // is lost either way.
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return 0;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO verification_links
                (agreement_id, resource_name, purpose, url, official_source)
            VALUES
                (:agreement_id, :resource_name, :purpose, :url, :official_source)
        ");
        $stmt->execute([
            ':agreement_id'    => $agreementId,
            ':resource_name'   => $nameCol !== null ? ($values[$nameCol] ?: null) : null,
            ':purpose'         => $purposeCol !== null ? ($values[$purposeCol] ?: null) : null,
            ':url'             => $url,
            ':official_source' => $sourceCol !== null ? ($values[$sourceCol] ?: null) : null,
        ]);

        return 1;
    }

    private array $sectionTypeIdCache = [];

    private function getSectionTypeId(string $code): int
    {
        if (isset($this->sectionTypeIdCache[$code])) {
            return $this->sectionTypeIdCache[$code];
        }

        $stmt = $this->pdo->prepare("SELECT id FROM section_types WHERE code = :code LIMIT 1");
        $stmt->execute([':code' => $code]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            throw new \RuntimeException("Section type '{$code}' not found — run --reference first.");
        }

        $this->sectionTypeIdCache[$code] = (int) $id;
        return (int) $id;
    }
}
