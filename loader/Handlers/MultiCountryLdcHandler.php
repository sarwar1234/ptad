<?php

declare(strict_types=1);

namespace PtadLoader\Handlers;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Ptad\Database\Connection;
use Ptad\Helpers\HsCode;
use Ptad\Helpers\RateParser;
use PtadLoader\Support\ExceptionsLog;

/**
 * ============================================================
 * PTAD — Multi-Country LDC/Non-LDC Format Handler
 * ============================================================
 * Handles the "multi_country_ldc" format family per Loader Spec
 * Table B3: one tariff_lines row PER COUNTRY (read from a
 * per-row country column), with up to TWO tariff_rates rows per
 * line — one condition='ldc', one condition='non_ldc' — since a
 * single product can have different rates for LDC vs non-LDC
 * exporting members.
 *
 * Covers three real shapes, controlled by "sheet_shape" in config:
 *
 *  - "flat_single_header" (SAPTA, PTN): one fixed header row, one
 *    Country column that changes value row by row. SAPTA has real
 *    separate LDC/non-LDC columns; PTN has only ONE rate column
 *    (loaded as condition='standard' per its config note, since
 *    the source data itself doesn't distinguish LDC/non-LDC).
 *
 *  - "vertical_country_blocks" (GSTP): NO single fixed header row.
 *    45 country sections stacked vertically, each with its own
 *    title row ("CONCESSIONS GRANTED BY <COUNTRY>") followed by
 *    its own header row (same column POSITIONS every time, even
 *    though the header LABELS vary per block — confirmed in
 *    Step 5's inspection), followed by that country's data rows.
 *    This handler scans for each title row and processes the
 *    block that follows it, rather than relying on one fixed
 *    header_row from config.
 *
 * Per Document B: description is the PRIMARY reference for these
 * historical/pre-HS codes (GSTP/PTN), since the codes themselves
 * are not standard HS — hs_code_norm is still computed for
 * whatever digits exist, but search should weight description
 * more heavily for these two modules specifically (a front-end/
 * API concern, not something this loader needs to change).
 * ============================================================
 */
final class MultiCountryLdcHandler
{
    private PDO $pdo;
    private array $config;
    private string $moduleCode;
    private ExceptionsLog $exceptions;
    private int $agreementId;
    private array $countryIdCache = [];

    public function __construct(string $moduleCode, array $config)
    {
        $this->moduleCode = $moduleCode;
        $this->config = $config;
        $this->pdo = Connection::get();
        $this->exceptions = new ExceptionsLog($moduleCode);
    }

    public function run(): array
    {
        $this->pdo->beginTransaction();

        try {
            $this->agreementId = $this->upsertAgreement();
            $this->wipeExistingTariffLines();

            $totalLoaded = 0;
            foreach ($this->config['tariff_sheets'] as $sheetConfig) {
                $shape = $sheetConfig['sheet_shape'] ?? 'flat_single_header';
                $totalLoaded += $shape === 'vertical_country_blocks'
                    ? $this->loadVerticalBlockSheet($sheetConfig)
                    : $this->loadFlatSheet($sheetConfig);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->exceptions->close();
            throw $e;
        }

        $this->exceptions->close();

        return [
            'agreement_id'     => $this->agreementId,
            'lines_loaded'     => $totalLoaded,
            'exceptions_count' => $this->exceptions->count(),
            'exceptions_path'  => $this->exceptions->path(),
        ];
    }

    private function upsertAgreement(): int
    {
        $a = $this->config['agreement'];

        $sql = "INSERT INTO agreements
                    (code, short_name, full_name, type, source_workbook,
                     list_type, coverage, staging, entry_into_force,
                     staging_horizon_yrs, default_ceiling_pct, status)
                VALUES
                    (:code, :short_name, :full_name, :type, :source_workbook,
                     :list_type, :coverage, :staging, :entry_into_force,
                     :staging_horizon_yrs, :default_ceiling_pct, :status)
                ON DUPLICATE KEY UPDATE
                    short_name = VALUES(short_name), full_name = VALUES(full_name),
                    source_workbook = VALUES(source_workbook), coverage = VALUES(coverage),
                    status = VALUES(status), id = LAST_INSERT_ID(id)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':code' => $a['code'], ':short_name' => $a['short_name'], ':full_name' => $a['full_name'],
            ':type' => $a['type'], ':source_workbook' => $a['source_workbook'],
            ':list_type' => $a['list_type'], ':coverage' => $a['coverage'], ':staging' => $a['staging'],
            ':entry_into_force' => $a['entry_into_force'], ':staging_horizon_yrs' => $a['staging_horizon_yrs'],
            ':default_ceiling_pct' => $a['default_ceiling_pct'], ':status' => $a['status'] ?? 'in_force',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function wipeExistingTariffLines(): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM tariff_lines WHERE agreement_id = :id");
        $stmt->execute([':id' => $this->agreementId]);
    }

    /**
     * SAPTA / PTN: one fixed header row, Country column changes per row.
     */
    private function loadFlatSheet(array $sheetConfig): int
    {
        [$spreadsheet, $sheet] = $this->openSheet($sheetConfig);
        $cols = $sheetConfig['columns'];
        $headerRow = $sheetConfig['header_row'];
        $highestRow = $sheet->getHighestRow();
        $hasLdcSplit = isset($cols['preferential_rate_ldc']);

        $loaded = 0;

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $countryName = trim((string) $sheet->getCell($cols['member_country_col'] . $row)->getValue());
            $hsCodeRaw = (string) $sheet->getCell($cols['hs_code'] . $row)->getValue();

            if (trim($hsCodeRaw) === '' || $countryName === '') {
                continue;
            }

            $loaded += $this->insertLineWithRates(
                $sheetConfig, $sheet, $row, $countryName, $cols, $hasLdcSplit
            );
        }

        $this->closeSheet($spreadsheet, $sheet);
        return $loaded;
    }

    /**
     * GSTP: 45 stacked country blocks, each with its own title + header
     * row, then data rows, then the next block. Column POSITIONS are
     * consistent across every block (confirmed in Step 5 inspection)
     * even though the header LABEL TEXT varies per country.
     */
    private function loadVerticalBlockSheet(array $sheetConfig): int
    {
        [$spreadsheet, $sheet] = $this->openSheet($sheetConfig);
        $cols = $sheetConfig['columns'];
        $highestRow = $sheet->getHighestRow();
        $titlePattern = $sheetConfig['block_title_pattern'] ?? 'CONCESSIONS GRANTED BY';

        $loaded = 0;
        $currentCountry = null;
        $inHeaderRow = false;

        for ($row = 1; $row <= $highestRow; $row++) {
            $colA = trim((string) $sheet->getCell('A' . $row)->getValue());
            $colB = trim((string) $sheet->getCell('B' . $row)->getValue());
            $colC = trim((string) $sheet->getCell('C' . $row)->getValue());

            // Confirmed by direct inspection: the title text ("GLOBAL
            // SYSTEM OF TRADE PREFERENCES - CONCESSIONS GRANTED BY
            // ALGERIA") lives in COLUMN C. Column B sometimes ALSO has
            // the plain country name on the same row, but not always
            // (confirmed blank for Bangladesh/Bolivia/Brazil/others) —
            // so the country name is extracted from column C's text via
            // regex, which is present on every block's title row.
            if (str_contains(strtoupper($colC), $titlePattern)) {
                if (preg_match('/GRANTED BY\s+(.+)$/i', $colC, $m)) {
                    // Source text is in ALL CAPS; convert to title case
                    // to match the Countries reference data's casing
                    // (e.g. "ALGERIA" -> "Algeria").
                    $currentCountry = mb_convert_case(trim($m[1]), MB_CASE_TITLE);
                }
                $inHeaderRow = true; // next non-title row is this block's header
                continue;
            }

            // The row immediately after a title row is this block's
            // header row (column labels vary, positions don't) — skip
            // it, don't treat it as data.
            if ($inHeaderRow) {
                $inHeaderRow = false;
                continue;
            }

            if ($currentCountry === null) {
                continue; // haven't hit the first block's title yet
            }

            $hsCodeRaw = (string) $sheet->getCell($cols['hs_code'] . $row)->getValue();
            if (trim($hsCodeRaw) === '' || $colA === '') {
                continue; // blank row between blocks, or end of data
            }

            $loaded += $this->insertLineWithRates(
                $sheetConfig, $sheet, $row, $currentCountry, $cols, false
            );
        }

        $this->closeSheet($spreadsheet, $sheet);
        return $loaded;
    }

    private function insertLineWithRates(
        array $sheetConfig,
        $sheet,
        int $row,
        string $countryName,
        array $cols,
        bool $hasLdcSplit
    ): int {
        $hsCodeRawCell = (string) $sheet->getCell($cols['hs_code'] . $row)->getValue();

        // Some GSTP cells legitimately list MULTIPLE HS codes for one
        // concession row, separated by line breaks within the cell
        // (confirmed by direct inspection — this is real, valid source
        // data, not a formatting error). Split and create one
        // tariff_lines row per code rather than concatenating them
        // into one invalid code or dropping the row.
        $hsCodesRaw = preg_split('/[\r\n]+/', $hsCodeRawCell);

        $insertedCount = 0;
        foreach ($hsCodesRaw as $singleHsCodeRaw) {
            $singleHsCodeRaw = trim($singleHsCodeRaw);
            // Strip a leading "Ex " (means "ex-heading", a partial/
            // example carve-out under that heading) before normalizing —
            // preserved instead in remarks so the qualifier isn't lost.
            $isExHeading = (bool) preg_match('/^Ex\s+/i', $singleHsCodeRaw);
            $cleanedCode = preg_replace('/^Ex\s+/i', '', $singleHsCodeRaw);

            $inserted = $this->insertSingleLine(
                $sheetConfig, $sheet, $row, $countryName, $cols, $hasLdcSplit,
                $cleanedCode, $isExHeading
            );
            $insertedCount += $inserted;
        }

        return $insertedCount;
    }

    private function insertSingleLine(
        array $sheetConfig,
        $sheet,
        int $row,
        string $countryName,
        array $cols,
        bool $hasLdcSplit,
        string $hsCodeRaw,
        bool $isExHeading
    ): int {
        $hs = HsCode::normalize($hsCodeRaw);

        if (!HsCode::isPlausible($hs['norm'])) {
            $this->exceptions->record($sheetConfig['sheet_name'], $row, $hsCodeRaw, 'HS code not plausible after normalization');
            return 0;
        }

        $countryId = $this->resolveCountryId($countryName);
        if ($countryId === null) {
            return 0; // logged inside resolveCountryId
        }
        $this->ensureMember($countryId);

        $description = $cols['description'] ? (string) $sheet->getCell($cols['description'] . $row)->getValue() : null;
        $mfnCellRaw  = $cols['mfn_rate'] ? (string) $sheet->getCell($cols['mfn_rate'] . $row)->getValue() : null;
        $remarks     = $cols['remarks'] ? (string) $sheet->getCell($cols['remarks'] . $row)->getValue() : '';
        $sourceRef   = $cols['source_reference'] ? (string) $sheet->getCell($cols['source_reference'] . $row)->getValue() : null;

        if ($isExHeading) {
            $remarks = trim(($remarks !== '' ? $remarks . ' | ' : '') . 'Ex-heading (partial/example scope under this HS heading, per source cell)');
        }

        $mfnParsed = RateParser::parse($mfnCellRaw);

        $insertLine = $this->pdo->prepare(
            "INSERT INTO tariff_lines
                (agreement_id, member_country_id, hs_code_raw, hs_code_norm, hs_digits, hs6,
                 product_desc, mfn_kind, mfn_value, mfn_text, mfn_meaning, remarks, source_reference)
             VALUES
                (:agreement_id, :member_country_id, :hs_code_raw, :hs_code_norm, :hs_digits, :hs6,
                 :product_desc, :mfn_kind, :mfn_value, :mfn_text, 'base_at_negotiation', :remarks, :source_reference)"
        );
        $insertLine->execute([
            ':agreement_id'      => $this->agreementId,
            ':member_country_id' => $countryId,
            ':hs_code_raw'       => $hs['raw'],
            ':hs_code_norm'      => $hs['norm'],
            ':hs_digits'         => $hs['digits'],
            ':hs6'               => $hs['hs6'],
            ':product_desc'      => $description,
            ':mfn_kind'          => $mfnParsed['rate_kind'],
            ':mfn_value'         => $mfnParsed['rate_value'],
            ':mfn_text'          => $mfnParsed['rate_text'],
            ':remarks'           => $remarks ?: null,
            ':source_reference'  => $sourceRef ?: null,
        ]);
        $lineId = (int) $this->pdo->lastInsertId();

        $insertRate = $this->pdo->prepare(
            "INSERT INTO tariff_rates
                (tariff_line_id, `condition`, component, rate_kind, rate_value, rate_text,
                 effective_advalorem, advantage_value, advantage_text)
             VALUES
                (:tariff_line_id, :condition, 'total', :rate_kind, :rate_value, :rate_text,
                 :effective_advalorem, :advantage_value, :advantage_text)"
        );

        if ($hasLdcSplit) {
            // SAPTA: create ONLY the rate rows that actually have a
            // value — per Step 5's finding, some lines are LDC-exclusive
            // with genuinely no non-LDC concession at all; we never
            // fabricate a non-existent row just to have a pair.
            $nonLdcRaw = $cols['preferential_rate_non_ldc'] ? (string) $sheet->getCell($cols['preferential_rate_non_ldc'] . $row)->getValue() : null;
            $ldcRaw = $cols['preferential_rate_ldc'] ? (string) $sheet->getCell($cols['preferential_rate_ldc'] . $row)->getValue() : null;
            $advNonLdcRaw = $cols['tariff_advantage_non_ldc'] ? (string) $sheet->getCell($cols['tariff_advantage_non_ldc'] . $row)->getValue() : null;
            $advLdcRaw = $cols['tariff_advantage_ldc'] ? (string) $sheet->getCell($cols['tariff_advantage_ldc'] . $row)->getValue() : null;

            if (trim((string) $nonLdcRaw) !== '') {
                $p = RateParser::parse($nonLdcRaw);
                $a = RateParser::parse($advNonLdcRaw);
                $insertRate->execute([
                    ':tariff_line_id' => $lineId, ':condition' => 'non_ldc',
                    ':rate_kind' => $p['rate_kind'], ':rate_value' => $p['rate_value'], ':rate_text' => $p['rate_text'],
                    ':effective_advalorem' => $p['effective_advalorem'],
                    ':advantage_value' => $a['rate_value'], ':advantage_text' => $a['rate_text'],
                ]);
            }
            if (trim((string) $ldcRaw) !== '') {
                $p = RateParser::parse($ldcRaw);
                $a = RateParser::parse($advLdcRaw);
                $insertRate->execute([
                    ':tariff_line_id' => $lineId, ':condition' => 'ldc',
                    ':rate_kind' => $p['rate_kind'], ':rate_value' => $p['rate_value'], ':rate_text' => $p['rate_text'],
                    ':effective_advalorem' => $p['effective_advalorem'],
                    ':advantage_value' => $a['rate_value'], ':advantage_text' => $a['rate_text'],
                ]);
            }
        } else {
            // PTN / GSTP: single rate column, no LDC/non-LDC distinction
            // in the source data — stored as condition='standard'.
            $prefRaw = $cols['preferential_rate'] ? (string) $sheet->getCell($cols['preferential_rate'] . $row)->getValue() : null;
            $p = RateParser::parse($prefRaw);
            $insertRate->execute([
                ':tariff_line_id' => $lineId, ':condition' => 'standard',
                ':rate_kind' => $p['rate_kind'], ':rate_value' => $p['rate_value'], ':rate_text' => $p['rate_text'],
                ':effective_advalorem' => $p['effective_advalorem'],
                ':advantage_value' => null, ':advantage_text' => null,
            ]);
        }

        return 1;
    }

    private function ensureMember(int $countryId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO agreement_members (agreement_id, country_id, role, status)
             VALUES (:agreement_id, :country_id, 'party', 'implemented')
             ON DUPLICATE KEY UPDATE role = VALUES(role)"
        );
        $stmt->execute([':agreement_id' => $this->agreementId, ':country_id' => $countryId]);
    }

    private function openSheet(array $sheetConfig): array
    {
        $workbookName = $sheetConfig['source_workbook'] ?? $this->config['agreement']['source_workbook'];
        $filePath = __DIR__ . '/../../data/AGREEMENT_MODULES_29/' . $workbookName;

        if (!file_exists($filePath)) {
            throw new \RuntimeException("Workbook not found: {$filePath}");
        }

        $spreadsheet = IOFactory::createReaderForFile($filePath)->load($filePath);
        $sheet = $spreadsheet->getSheetByName($sheetConfig['sheet_name']);

        if ($sheet === null) {
            throw new \RuntimeException("Sheet '{$sheetConfig['sheet_name']}' not found in {$filePath}");
        }

        return [$spreadsheet, $sheet];
    }

    private function closeSheet($spreadsheet, $sheet): void
    {
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $sheet);
        gc_collect_cycles();
    }

    private function resolveCountryId(string $name): ?int
    {
        // Some GSTP title rows include a parenthetical annotation after
        // the country name (confirmed: "Paraguay (Mercosur)") — this is
        // a real-world grouping note, not part of the country's actual
        // name, and must be stripped before lookup or it never matches
        // the countries table.
        $name = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $name));

        $canonical = \PtadLoader\Reference\Countries::resolveCanonicalName($name);

        if (isset($this->countryIdCache[$canonical])) {
            return $this->countryIdCache[$canonical];
        }

        $stmt = $this->pdo->prepare("SELECT id FROM countries WHERE LOWER(name) = LOWER(:name) LIMIT 1");
        $stmt->execute([':name' => $canonical]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            $this->exceptions->record('(country lookup)', 0, '', "Country '{$canonical}' not found in countries table — run --reference first");
            return null;
        }

        $this->countryIdCache[$canonical] = (int) $id;
        return (int) $id;
    }
}
