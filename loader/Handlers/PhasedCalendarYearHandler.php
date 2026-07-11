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
 * PTAD — Phased Calendar-Year Format Handler
 * ============================================================
 * Handles the "phased_calendar_year" format family per Loader
 * Spec Table B3: reads a hidden "*_Raw" sheet's year columns
 * (one column per calendar year) and creates ONE tariff_rates
 * row PER YEAR per tariff line — not just one standing rate.
 *
 * Covers two real shapes, controlled by "rate_structure" on each
 * tariff_sheets entry (set per-sheet since D-8 mixes both within
 * one module — confirmed in Step 5's inspection):
 *
 *  - "phased_years": has a raw_sheet with numeric year columns
 *    (China: 2020-2034 in China_Raw/Pakistan_Raw; D-8's
 *    Indonesia/Malaysia/Pakistan/Türkiye sheets, read directly
 *    from their visible sheet's own year columns — no separate
 *    raw sheet needed for D-8 per its config, since its year
 *    columns are already on the main sheet).
 *
 *  - "single_standing_rate": D-8's Bangladesh/Iran sheets, which
 *    have no phasing at all — one plain rate column, loaded as
 *    a single tariff_rates row with applies_year=NULL (a standing
 *    rate, same shape as SimpleBilateralHandler's output).
 *
 * China-specific note (confirmed in Step 5 / earlier deep scan):
 * years 2030-2034 intentionally REPEAT the Year-10 (2029) rate
 * per the workbook's own Sources_Notes sheet — this handler loads
 * whatever value is in each year's column as-is, including these
 * repeated values, since they ARE the correct rate for those
 * years per the source data (not a bug to "fix" by skipping).
 * ============================================================
 */
final class PhasedCalendarYearHandler
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
            $this->upsertMembers();
            $this->wipeExistingTariffLines();

            $totalLoaded = 0;
            foreach ($this->config['tariff_sheets'] as $sheetConfig) {
                $structure = $sheetConfig['rate_structure'] ?? 'single_standing_rate';
                $totalLoaded += $structure === 'phased_years'
                    ? $this->loadPhasedSheet($sheetConfig)
                    : $this->loadStandingRateSheet($sheetConfig);
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
                     list_type, coverage, staging, anniversary_month, anniversary_day,
                     entry_into_force, staging_horizon_yrs, default_ceiling_pct, status)
                VALUES
                    (:code, :short_name, :full_name, :type, :source_workbook,
                     :list_type, :coverage, :staging, :anniversary_month, :anniversary_day,
                     :entry_into_force, :staging_horizon_yrs, :default_ceiling_pct, :status)
                ON DUPLICATE KEY UPDATE
                    short_name = VALUES(short_name), full_name = VALUES(full_name),
                    source_workbook = VALUES(source_workbook), coverage = VALUES(coverage),
                    status = VALUES(status), id = LAST_INSERT_ID(id)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':code' => $a['code'], ':short_name' => $a['short_name'], ':full_name' => $a['full_name'],
            ':type' => $a['type'], ':source_workbook' => $a['source_workbook'],
            ':list_type' => $a['list_type'], ':coverage' => $a['coverage'], ':staging' => $a['staging'],
            ':anniversary_month' => $a['anniversary_month'] ?? null, ':anniversary_day' => $a['anniversary_day'] ?? null,
            ':entry_into_force' => $a['entry_into_force'], ':staging_horizon_yrs' => $a['staging_horizon_yrs'],
            ':default_ceiling_pct' => $a['default_ceiling_pct'], ':status' => $a['status'] ?? 'in_force',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function upsertMembers(): void
    {
        $sql = "INSERT INTO agreement_members (agreement_id, country_id, role, status)
                VALUES (:agreement_id, :country_id, 'party', :status)
                ON DUPLICATE KEY UPDATE status = VALUES(status)";
        $stmt = $this->pdo->prepare($sql);

        foreach ($this->config['tariff_sheets'] as $sheet) {
            // BUG FOUND during frontend member-count testing: this only
            // ever checked 'member_country', but China's config (a
            // two-direction bilateral, not a per-member module like
            // D-8) uses 'import_country' instead — confirmed real
            // consequence: China had ZERO rows in agreement_members
            // despite tariff data loading perfectly for both directions.
            // Falls back to import_country, matching the same pattern
            // already used elsewhere in this handler (loadPhasedSheet).
            $countryName = $sheet['member_country'] ?? $sheet['import_country'] ?? null;
            if (empty($countryName)) {
                continue;
            }
            $countryId = $this->resolveCountryId($countryName);
            if ($countryId === null) {
                continue;
            }
            $stmt->execute([
                ':agreement_id' => $this->agreementId,
                ':country_id'   => $countryId,
                ':status'       => 'implemented',
            ]);
        }

        // D-8 specific: non-implementer members (Egypt, Nigeria) get a
        // status='not_implemented' row with NO tariff data, per the
        // Schema Companion Guide's explicit design for this — confirmed
        // via the "Status - Egypt"/"Status - Nigeria" sheets we
        // inspected in Step 6 (member_overview section content, not
        // tariff data).
        foreach ($this->config['non_implemented_members'] ?? [] as $name) {
            $countryId = $this->resolveCountryId($name);
            if ($countryId === null) {
                continue;
            }
            $stmt->execute([
                ':agreement_id' => $this->agreementId,
                ':country_id'   => $countryId,
                ':status'       => 'not_implemented',
            ]);
        }
    }

    private function wipeExistingTariffLines(): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM tariff_lines WHERE agreement_id = :id");
        $stmt->execute([':id' => $this->agreementId]);
    }

    /**
     * China (via China_Raw/Pakistan_Raw) and D-8's phased members:
     * reads a set of year columns and creates one tariff_rates row
     * per year, per tariff line.
     */
    private function loadPhasedSheet(array $sheetConfig): int
    {
        // Prefer a separate raw_sheet if the config defines one
        // (China's case); otherwise read year columns directly off
        // the sheet named in tariff_sheets (D-8's case).
        $raw = $sheetConfig['raw_sheet'] ?? null;
        $sourceSheetName = $raw['sheet_name'] ?? $sheetConfig['sheet_name'];
        $headerRow = $raw['header_row'] ?? $sheetConfig['header_row'];
        $hsCol = $raw['hs_code_col'] ?? $sheetConfig['columns']['hs_code'];
        $descCol = $raw['description_col'] ?? $sheetConfig['columns']['description'];
        $mfnCol = $raw['mfn_col'] ?? $sheetConfig['columns']['mfn_rate'];
        $categoryCol = $raw['category_col'] ?? null;
        $yearColumns = $raw['year_columns'] ?? $sheetConfig['year_columns'];
        $remarksCol = $raw['remarks_col'] ?? $sheetConfig['columns']['remarks'] ?? null;
        $sourceRefCol = $sheetConfig['columns']['source_reference'] ?? null;

        $workbookName = $sheetConfig['source_workbook'] ?? $this->config['agreement']['source_workbook'];
        $filePath = __DIR__ . '/../../data/AGREEMENT_MODULES_29/' . $workbookName;

        if (!file_exists($filePath)) {
            throw new \RuntimeException("Workbook not found: {$filePath}");
        }

        $spreadsheet = IOFactory::createReaderForFile($filePath)->load($filePath);
        $sheet = $spreadsheet->getSheetByName($sourceSheetName);

        if ($sheet === null) {
            throw new \RuntimeException("Sheet '{$sourceSheetName}' not found in {$filePath}");
        }

        $importCountryId = $this->resolveCountryId($sheetConfig['import_country'] ?? $sheetConfig['member_country']);
        $highestRow = $sheet->getHighestRow();
        $loaded = 0;

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $hsCodeRaw = (string) $sheet->getCell($hsCol . $row)->getValue();

            if (trim($hsCodeRaw) === '') {
                continue;
            }

            $hs = HsCode::normalize($hsCodeRaw);
            if (!HsCode::isPlausible($hs['norm'])) {
                $this->exceptions->record($sourceSheetName, $row, $hsCodeRaw, 'HS code not plausible after normalization');
                continue;
            }

            $description = $descCol ? (string) $sheet->getCell($descCol . $row)->getValue() : null;
            $mfnCellRaw = $mfnCol ? (string) $sheet->getCell($mfnCol . $row)->getValue() : null;
            $stagingCategory = $categoryCol ? (string) $sheet->getCell($categoryCol . $row)->getValue() : null;
            $remarks = $remarksCol ? (string) $sheet->getCell($remarksCol . $row)->getValue() : null;
            $sourceRef = $sourceRefCol ? (string) $sheet->getCell($sourceRefCol . $row)->getValue() : null;

            $mfnParsed = RateParser::parse($mfnCellRaw);

            $insertLine = $this->pdo->prepare(
                "INSERT INTO tariff_lines
                    (agreement_id, import_country_id, member_country_id, hs_code_raw, hs_code_norm, hs_digits, hs6,
                     product_desc, mfn_kind, mfn_value, mfn_text, mfn_meaning, staging_category, remarks, source_reference)
                 VALUES
                    (:agreement_id, :import_country_id, :member_country_id, :hs_code_raw, :hs_code_norm, :hs_digits, :hs6,
                     :product_desc, :mfn_kind, :mfn_value, :mfn_text, 'base_at_negotiation', :staging_category, :remarks, :source_reference)"
            );
            $insertLine->execute([
                ':agreement_id'      => $this->agreementId,
                ':import_country_id' => $importCountryId,
                ':member_country_id' => $importCountryId,
                ':hs_code_raw'       => $hs['raw'],
                ':hs_code_norm'      => $hs['norm'],
                ':hs_digits'         => $hs['digits'],
                ':hs6'               => $hs['hs6'],
                ':product_desc'      => $description,
                ':mfn_kind'          => $mfnParsed['rate_kind'],
                ':mfn_value'         => $mfnParsed['rate_value'],
                ':mfn_text'          => $mfnParsed['rate_text'],
                ':staging_category'  => $stagingCategory,
                ':remarks'           => $remarks ?: null,
                ':source_reference'  => $sourceRef ?: null,
            ]);
            $lineId = (int) $this->pdo->lastInsertId();

            $insertRate = $this->pdo->prepare(
                "INSERT INTO tariff_rates
                    (tariff_line_id, `condition`, component, applies_year, rate_kind, rate_value, rate_text, effective_advalorem)
                 VALUES
                    (:tariff_line_id, 'standard', 'total', :applies_year, :rate_kind, :rate_value, :rate_text, :effective_advalorem)"
            );

            // One tariff_rates row PER YEAR — including years that
            // intentionally repeat an earlier year's value (e.g.
            // China's 2030-2034 repeating the 2029/Year-10 rate).
            foreach ($yearColumns as $year => $col) {
                $yearRateRaw = (string) $sheet->getCell($col . $row)->getValue();
                $p = RateParser::parse($yearRateRaw);
                $insertRate->execute([
                    ':tariff_line_id'      => $lineId,
                    ':applies_year'        => (int) $year,
                    ':rate_kind'           => $p['rate_kind'],
                    ':rate_value'          => $p['rate_value'],
                    ':rate_text'           => $p['rate_text'],
                    ':effective_advalorem' => $p['effective_advalorem'],
                ]);
            }

            $loaded++;
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $sheet);
        gc_collect_cycles();

        return $loaded;
    }

    /**
     * D-8's Bangladesh/Iran: no phasing at all, one standing rate.
     */
    private function loadStandingRateSheet(array $sheetConfig): int
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

        $cols = $sheetConfig['columns'];
        $headerRow = $sheetConfig['header_row'];
        $highestRow = $sheet->getHighestRow();
        $importCountryId = $this->resolveCountryId($sheetConfig['import_country'] ?? $sheetConfig['member_country']);

        $loaded = 0;

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $hsCodeRaw = (string) $sheet->getCell($cols['hs_code'] . $row)->getValue();
            if (trim($hsCodeRaw) === '') {
                continue;
            }

            $hs = HsCode::normalize($hsCodeRaw);
            if (!HsCode::isPlausible($hs['norm'])) {
                $this->exceptions->record($sheetConfig['sheet_name'], $row, $hsCodeRaw, 'HS code not plausible after normalization');
                continue;
            }

            $description = $cols['description'] ? (string) $sheet->getCell($cols['description'] . $row)->getValue() : null;
            $mfnCellRaw = $cols['mfn_rate'] ? (string) $sheet->getCell($cols['mfn_rate'] . $row)->getValue() : null;
            $prefCellRaw = $cols['preferential_rate'] ? (string) $sheet->getCell($cols['preferential_rate'] . $row)->getValue() : null;
            $remarks = $cols['remarks'] ? (string) $sheet->getCell($cols['remarks'] . $row)->getValue() : null;
            $sourceRef = $cols['source_reference'] ? (string) $sheet->getCell($cols['source_reference'] . $row)->getValue() : null;

            $mfnParsed = RateParser::parse($mfnCellRaw);
            $prefParsed = RateParser::parse($prefCellRaw);

            $insertLine = $this->pdo->prepare(
                "INSERT INTO tariff_lines
                    (agreement_id, import_country_id, member_country_id, hs_code_raw, hs_code_norm, hs_digits, hs6,
                     product_desc, mfn_kind, mfn_value, mfn_text, mfn_meaning, remarks, source_reference)
                 VALUES
                    (:agreement_id, :import_country_id, :member_country_id, :hs_code_raw, :hs_code_norm, :hs_digits, :hs6,
                     :product_desc, :mfn_kind, :mfn_value, :mfn_text, 'base_at_negotiation', :remarks, :source_reference)"
            );
            $insertLine->execute([
                ':agreement_id'      => $this->agreementId,
                ':import_country_id' => $importCountryId,
                ':member_country_id' => $importCountryId,
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
                    (tariff_line_id, `condition`, component, applies_year, rate_kind, rate_value, rate_text, effective_advalorem)
                 VALUES
                    (:tariff_line_id, 'standard', 'total', NULL, :rate_kind, :rate_value, :rate_text, :effective_advalorem)"
            );
            $insertRate->execute([
                ':tariff_line_id'      => $lineId,
                ':rate_kind'           => $prefParsed['rate_kind'],
                ':rate_value'          => $prefParsed['rate_value'],
                ':rate_text'           => $prefParsed['rate_text'],
                ':effective_advalorem' => $prefParsed['effective_advalorem'],
            ]);

            $loaded++;
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $sheet);
        gc_collect_cycles();

        return $loaded;
    }

    private function resolveCountryId(string $name): ?int
    {
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
