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
 * PTAD — Phased Multi-Component Format Handler
 * ============================================================
 * Handles the "phased_multi_component" format family — Türkiye
 * PTA, confirmed by direct inspection (correcting an earlier
 * Step 5 assumption that its full phased schedule didn't exist —
 * it does, directly on the visible sheets, just far to the right,
 * A1:BD272 / A1:AS320, beyond what the first pass inspected).
 *
 * Three duty components per line, each phased over 11 years
 * (2023-2033): CD (Customs Duty), RD (Regulatory Duty — labeled
 * "AFL" on the Türkiye-side sheet specifically, same concept),
 * and ACD (Additional Customs Duty). One tariff_rates row is
 * created per (component, year) pair — up to 33 rows per line.
 *
 * Also populates hs_transpositions for the Türkiye-side sheet's
 * 12-digit Turkish GTIP <-> 8-digit FBR code pairs, per schema
 * Section 9's design for exactly this purpose.
 * ============================================================
 */
final class PhasedMultiComponentHandler
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
                $totalLoaded += $this->loadSheet($sheetConfig);
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
                    anniversary_month = VALUES(anniversary_month), anniversary_day = VALUES(anniversary_day),
                    entry_into_force = VALUES(entry_into_force), staging_horizon_yrs = VALUES(staging_horizon_yrs),
                    status = VALUES(status), id = LAST_INSERT_ID(id)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':code' => $a['code'], ':short_name' => $a['short_name'], ':full_name' => $a['full_name'],
            ':type' => $a['type'], ':source_workbook' => $a['source_workbook'],
            ':list_type' => $a['list_type'], ':coverage' => $a['coverage'], ':staging' => $a['staging'],
            ':anniversary_month' => $a['anniversary_month'], ':anniversary_day' => $a['anniversary_day'],
            ':entry_into_force' => $a['entry_into_force'], ':staging_horizon_yrs' => $a['staging_horizon_yrs'],
            ':default_ceiling_pct' => $a['default_ceiling_pct'], ':status' => $a['status'] ?? 'in_force',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function upsertMembers(): void
    {
        $sql = "INSERT INTO agreement_members (agreement_id, country_id, role, status)
                VALUES (:agreement_id, :country_id, 'party', 'implemented')
                ON DUPLICATE KEY UPDATE role = VALUES(role)";
        $stmt = $this->pdo->prepare($sql);

        $countryNames = array_unique(array_column($this->config['tariff_sheets'], 'import_country'));
        foreach ($countryNames as $name) {
            $countryId = $this->resolveCountryId($name);
            if ($countryId !== null) {
                $stmt->execute([':agreement_id' => $this->agreementId, ':country_id' => $countryId]);
            }
        }
    }

    private function wipeExistingTariffLines(): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM tariff_lines WHERE agreement_id = :id");
        $stmt->execute([':id' => $this->agreementId]);

        // Also clear this agreement's transposition rows so re-running
        // after an Excel edit doesn't accumulate stale duplicates.
        $stmt = $this->pdo->prepare("DELETE FROM hs_transpositions WHERE agreement_id = :id");
        $stmt->execute([':id' => $this->agreementId]);
    }

    private function loadSheet(array $sheetConfig): int
    {
        $workbookName = $this->config['agreement']['source_workbook'];
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
        $componentYearCols = $sheetConfig['component_year_columns'];
        $headerRow = $sheetConfig['header_row'];
        $highestRow = $sheet->getHighestRow();
        $importCountryId = $this->resolveCountryId($sheetConfig['import_country']);

        $insertLine = $this->pdo->prepare(
            "INSERT INTO tariff_lines
                (agreement_id, import_country_id, hs_code_raw, hs_code_norm, hs_digits, hs6,
                 product_desc, mfn_meaning, remarks)
             VALUES
                (:agreement_id, :import_country_id, :hs_code_raw, :hs_code_norm, :hs_digits, :hs6,
                 :product_desc, 'base_at_negotiation', :remarks)"
        );

        $insertRate = $this->pdo->prepare(
            "INSERT INTO tariff_rates
                (tariff_line_id, `condition`, component, applies_year, rate_kind, rate_value, rate_text, effective_advalorem)
             VALUES
                (:tariff_line_id, 'standard', :component, :applies_year, :rate_kind, :rate_value, :rate_text, :effective_advalorem)"
        );

        $insertTransposition = $cols['hs_code_12digit_turkish']
            ? $this->pdo->prepare(
                "INSERT INTO hs_transpositions
                    (agreement_id, from_system, from_code_raw, from_code_norm, to_system, to_code_raw, to_code_norm, authority)
                 VALUES
                    (:agreement_id, 'Turkish GTIP (12-digit)', :from_raw, :from_norm, 'Pakistan FBR (8-digit)', :to_raw, :to_norm, 'FBR Transposition List')"
            )
            : null;

        $loaded = 0;

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $hsCodeRawCell = (string) $sheet->getCell($cols['hs_code'] . $row)->getValue();
            if (trim($hsCodeRawCell) === '') {
                continue;
            }

            // Some rows legitimately list MULTIPLE 8-digit FBR codes for
            // one 12-digit Turkish line (confirmed by direct inspection —
            // a genuine one-to-many transposition case, e.g. "0804.1010,
            // 0804.1020"), separated by commas or line breaks. Split and
            // create one tariff_lines row per code, same principle
            // already applied to GSTP's multi-code cells, rather than
            // dropping the row or concatenating into one invalid code.
            $hsCodesRaw = preg_split('/[,\r\n]+/', $hsCodeRawCell);

            foreach ($hsCodesRaw as $singleHsCodeRaw) {
                $singleHsCodeRaw = trim($singleHsCodeRaw);
                if ($singleHsCodeRaw === '') {
                    continue;
                }
                $loaded += $this->loadOneLine(
                    $sheetConfig, $sheet, $row, $singleHsCodeRaw, $cols,
                    $componentYearCols, $importCountryId, $insertLine, $insertRate, $insertTransposition
                );
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $sheet);
        gc_collect_cycles();

        return $loaded;
    }

    private function loadOneLine(
        array $sheetConfig,
        $sheet,
        int $row,
        string $hsCodeRaw,
        array $cols,
        array $componentYearCols,
        ?int $importCountryId,
        $insertLine,
        $insertRate,
        $insertTransposition
    ): int {
        $hs = HsCode::normalize($hsCodeRaw);
        if (!HsCode::isPlausible($hs['norm'])) {
            $this->exceptions->record($sheetConfig['sheet_name'], $row, $hsCodeRaw, 'HS code not plausible after normalization');
            return 0;
        }

        $description = (string) $sheet->getCell($cols['description'] . $row)->getValue();
            // BUG FOUND during Gap #3 review: this column is a LIVE
            // Excel formula (an interactive date-selector display,
            // dependent on other helper cells), not static text.
            // ->getValue() returns the raw, unevaluated formula string
            // ("=IF($B$4=...)") rather than real text — confirmed
            // present in ALL 412 lines across both Türkiye-side and
            // Pakistan-side sheets. Rather than attempt to evaluate
            // this fragile, interactive formula (which depends on a
            // date-picker helper cell with no fixed value), remarks
            // is set to NULL when the cell is a formula — the actual
            // information it would have displayed (CD/RD/ACD rates
            // per year) is ALREADY correctly captured in tariff_rates,
            // so nothing is genuinely lost by not storing this
            // duplicate, un-evaluatable display string.
            $remarksRaw = $cols['remarks'] ? (string) $sheet->getCell($cols['remarks'] . $row)->getValue() : null;
            $remarks = ($remarksRaw !== null && str_starts_with(trim($remarksRaw), '=')) ? null : $remarksRaw;

        $insertLine->execute([
            ':agreement_id'      => $this->agreementId,
            ':import_country_id' => $importCountryId,
            ':hs_code_raw'       => $hs['raw'],
            ':hs_code_norm'      => $hs['norm'],
            ':hs_digits'         => $hs['digits'],
            ':hs6'               => $hs['hs6'],
            ':product_desc'      => $description,
            ':remarks'           => $remarks ?: null,
        ]);
        $lineId = (int) $this->pdo->lastInsertId();

        // 12<->8 digit transposition (Türkiye-side sheet only). When one
        // 12-digit Turkish code maps to several 8-digit Pakistan codes
        // (the multi-code cell case), this correctly records one
        // transposition pair per split code, per schema Section 9's
        // "one 8-digit code maps to several 12-digit codes... and is
        // general enough that any future transposition is just more rows".
        if ($insertTransposition !== null) {
            $turkishRaw = (string) $sheet->getCell($cols['hs_code_12digit_turkish'] . $row)->getValue();
            if (trim($turkishRaw) !== '') {
                $turkishNorm = HsCode::normalize($turkishRaw);
                $insertTransposition->execute([
                    ':agreement_id' => $this->agreementId,
                    ':from_raw'     => $turkishNorm['raw'],
                    ':from_norm'    => $turkishNorm['norm'],
                    ':to_raw'       => $hs['raw'],
                    ':to_norm'      => $hs['norm'],
                ]);
            }
        }

        // One tariff_rates row per (component, year) pair.
        foreach ($componentYearCols as $component => $yearCols) {
            foreach ($yearCols as $year => $col) {
                $cellValue = (string) $sheet->getCell($col . $row)->getValue();
                if (trim($cellValue) === '') {
                    continue; // this component/year genuinely not applicable to this line
                }
                $p = RateParser::parse($cellValue);
                $insertRate->execute([
                    ':tariff_line_id'      => $lineId,
                    ':component'           => $component,
                    ':applies_year'        => (int) $year,
                    ':rate_kind'           => $p['rate_kind'],
                    ':rate_value'          => $p['rate_value'],
                    ':rate_text'           => $p['rate_text'],
                    ':effective_advalorem' => $p['effective_advalorem'],
                ]);
            }
        }

        return 1;
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
