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
 * PTAD — Text Rate Format Handler
 * ============================================================
 * Handles the "text_rate" format family (per Loader Spec Table B3
 * "Text-rate / TARIC" row) — covers all GSP modules (Australia,
 * Canada, Switzerland, EU, Japan, Norway, New Zealand, the 5 EAEU
 * modules, Türkiye GSP, UK, USA). Preserves rate text verbatim
 * (e.g. "Free p/st", "MOP (50%)") via RateParser rather than
 * forcing a fabricated number.
 *
 * DIFFERENCES from SimpleBilateralHandler:
 *   - Single-country modules only (one granting country each,
 *     not two-directional like the bilaterals) — so only ONE
 *     tariff_sheets entry per config, not two.
 *   - Per-sheet source_workbook override supported, since the
 *     5 EAEU modules (Russia/Armenia/Belarus/Kazakhstan/Kyrgyzstan)
 *     share one config SHAPE but are 5 separate real files — each
 *     of those 5 configs sets its own agreement.source_workbook.
 *   - "unmapped_columns" support: any column a config flags here
 *     is appended into remarks (labeled with its original header)
 *     per the project's Unmapped Column Policy — NOTHING from the
 *     sheet is discarded, even columns with no dedicated schema
 *     field.
 *   - agreements.status is honoured from config (e.g. USA GSP is
 *     explicitly 'suspended' per its source data).
 * ============================================================
 */
final class TextRateHandler
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
                    source_workbook = VALUES(source_workbook), list_type = VALUES(list_type),
                    coverage = VALUES(coverage), staging = VALUES(staging),
                    anniversary_month = VALUES(anniversary_month), anniversary_day = VALUES(anniversary_day),
                    entry_into_force = VALUES(entry_into_force),
                    staging_horizon_yrs = VALUES(staging_horizon_yrs),
                    default_ceiling_pct = VALUES(default_ceiling_pct), status = VALUES(status),
                    id = LAST_INSERT_ID(id)";

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

        foreach ($this->config['tariff_sheets'] as $sheet) {
            if (empty($sheet['import_country'])) {
                continue;
            }
            // Bloc/multi-word entries like "Switzerland/Liechtenstein" are
            // split and each part resolved separately, so both get a
            // proper agreement_members row rather than one failed lookup.
            foreach (explode('/', $sheet['import_country']) as $part) {
                $countryId = $this->resolveCountryId(trim($part));
                if ($countryId === null) {
                    continue;
                }
                $stmt->execute([':agreement_id' => $this->agreementId, ':country_id' => $countryId]);
            }
        }
    }

    private function wipeExistingTariffLines(): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM tariff_lines WHERE agreement_id = :id");
        $stmt->execute([':id' => $this->agreementId]);
    }

    private function loadSheet(array $sheetConfig): int
    {
        // Per-sheet source_workbook override (needed for the 5 EAEU
        // configs sharing one shape but 5 real files); falls back to
        // the agreement-level source_workbook otherwise.
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
        $unmapped = $sheetConfig['unmapped_columns'] ?? [];
        $headerRow = $sheetConfig['header_row'];
        $highestRow = $sheet->getHighestRow();

        // Single granting country for this sheet (may itself be a
        // "A/B" bloc string like Switzerland/Liechtenstein — stored
        // as-is on tariff_lines since it's a single import_country_id
        // slot; the split only matters for agreement_members above).
        $importCountryId = $this->resolveCountryId(
            trim(explode('/', $sheetConfig['import_country'])[0])
        );

        $insertLine = $this->pdo->prepare(
            "INSERT INTO tariff_lines
                (agreement_id, import_country_id, hs_code_raw, hs_code_norm, hs_digits, hs6,
                 product_desc, mfn_kind, mfn_value, mfn_text, mfn_meaning, is_excluded, remarks, source_reference)
             VALUES
                (:agreement_id, :import_country_id, :hs_code_raw, :hs_code_norm, :hs_digits, :hs6,
                 :product_desc, :mfn_kind, :mfn_value, :mfn_text, 'base_at_negotiation', :is_excluded, :remarks, :source_reference)"
        );

        $insertRate = $this->pdo->prepare(
            "INSERT INTO tariff_rates
                (tariff_line_id, `condition`, component, rate_kind, rate_value, rate_text,
                 effective_advalorem, advantage_value, advantage_text)
             VALUES
                (:tariff_line_id, 'standard', 'total', :rate_kind, :rate_value, :rate_text,
                 :effective_advalorem, :advantage_value, :advantage_text)"
        );

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
            $mfnCellRaw  = $cols['mfn_rate'] ? (string) $sheet->getCell($cols['mfn_rate'] . $row)->getValue() : null;
            $prefCellRaw = $cols['preferential_rate'] ? (string) $sheet->getCell($cols['preferential_rate'] . $row)->getValue() : null;
            $advCellRaw  = $cols['tariff_advantage'] ? (string) $sheet->getCell($cols['tariff_advantage'] . $row)->getValue() : null;
            $exclusionRaw = $cols['exclusion_flag'] ? (string) $sheet->getCell($cols['exclusion_flag'] . $row)->getValue() : null;
            $remarks     = $cols['remarks'] ? (string) $sheet->getCell($cols['remarks'] . $row)->getValue() : '';
            $sourceRef   = $cols['source_reference'] ? (string) $sheet->getCell($cols['source_reference'] . $row)->getValue() : null;

            // Unmapped-column policy: append every flagged column's value
            // into remarks, labeled with its original header, so nothing
            // from the sheet is ever silently dropped.
            foreach ($unmapped as $col) {
                $value = trim((string) $sheet->getCell($col['letter'] . $row)->getValue());
                if ($value !== '') {
                    $remarks .= ($remarks !== '' ? ' | ' : '') . "{$col['label']}: {$value}";
                }
            }

            $isExcluded = $exclusionRaw !== null && trim($exclusionRaw) !== '' && strtolower(trim($exclusionRaw)) !== 'no';

            $mfnParsed = RateParser::parse($mfnCellRaw);
            $advParsed = RateParser::parse($advCellRaw);

            // BUG FOUND during Gap #3 review: the "preferential_rate"
            // cell in the 5 EAEU modules is a LIVE, uncalculated Excel
            // formula (confirmed: ALL rows across all 5 modules stored
            // the raw formula string, e.g. "=IF($G11=...)", with
            // resulting rate_value=NULL for every single row — a real,
            // previously undetected correctness gap, not just a
            // cosmetic remarks issue).
            //
            // The formula's own logic is simple and safely
            // reproducible directly: 75% of the MFN rate when the
            // "Pakistan USTP Eligibility" column (already captured in
            // $remarks via the unmapped-column policy) says eligible,
            // otherwise no preference. Rather than attempt to evaluate
            // the fragile original formula, this computes the same
            // result directly from data we already have and trust.
            $computedRateFormula = $this->config['rate_parsing']['computed_rate_formula'] ?? null;

            if ($computedRateFormula === 'mfn_times_75_if_eligible') {
                $isEligible = str_contains($remarks, 'Eligible for Pakistan');

                if (!$isEligible) {
                    $prefParsed = [
                        'rate_kind' => 'excluded', 'rate_value' => null,
                        'rate_text' => 'No USTP preference (not on eligible-goods list for Pakistan)',
                        'effective_advalorem' => null,
                    ];
                } elseif ($mfnParsed['rate_value'] !== null) {
                    $computed = round($mfnParsed['rate_value'] * 0.75, 4);
                    $prefParsed = [
                        'rate_kind' => 'ad_valorem', 'rate_value' => $computed,
                        'rate_text' => "{$computed}% (computed: 75% of {$mfnParsed['rate_value']}% MFN rate, per EAEU USTP formula)",
                        'effective_advalorem' => $computed,
                    ];
                } else {
                    // Eligible, but MFN itself isn't a clean number to
                    // compute 75% of (e.g. a specific duty) — don't
                    // fabricate a percentage from a non-percentage base.
                    $prefParsed = [
                        'rate_kind' => 'text_only', 'rate_value' => null,
                        'rate_text' => '75% of MFN/CCT duty rate (MFN is not a plain percentage — verify source-specific duty)',
                        'effective_advalorem' => null,
                    ];
                }
            } else {
                $prefParsed = RateParser::parse($prefCellRaw);
            }

            $insertLine->execute([
                ':agreement_id'      => $this->agreementId,
                ':import_country_id' => $importCountryId,
                ':hs_code_raw'       => $hs['raw'],
                ':hs_code_norm'      => $hs['norm'],
                ':hs_digits'         => $hs['digits'],
                ':hs6'               => $hs['hs6'],
                ':product_desc'      => $description,
                ':mfn_kind'          => $mfnParsed['rate_kind'],
                ':mfn_value'         => $mfnParsed['rate_value'],
                ':mfn_text'          => $mfnParsed['rate_text'],
                ':is_excluded'       => $isExcluded ? 1 : 0,
                ':remarks'           => $remarks !== '' ? $remarks : null,
                ':source_reference'  => $sourceRef ?: null,
            ]);

            $lineId = (int) $this->pdo->lastInsertId();

            $insertRate->execute([
                ':tariff_line_id'      => $lineId,
                ':rate_kind'           => $prefParsed['rate_kind'],
                ':rate_value'          => $prefParsed['rate_value'],
                ':rate_text'           => $prefParsed['rate_text'],
                ':effective_advalorem' => $prefParsed['effective_advalorem'],
                ':advantage_value'     => $advParsed['rate_value'],
                ':advantage_text'      => $advParsed['rate_text'],
            ]);

            $loaded++;
        }

        // Explicitly release the loaded workbook from memory before
        // moving to the next sheet/module. Without this, --all mode
        // (which processes many multi-megabyte Excel files in one PHP
        // process) accumulates memory across every file and eventually
        // exhausts even a generous memory_limit.
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

        $stmt = $this->pdo->prepare("SELECT id FROM countries WHERE name = :name LIMIT 1");
        $stmt->execute([':name' => $canonical]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            $this->exceptions->record('(agreement setup)', 0, '', "Country '{$canonical}' not found in countries table — run --reference first");
            return null;
        }

        $this->countryIdCache[$canonical] = (int) $id;
        return (int) $id;
    }
}
