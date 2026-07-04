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
 * PTAD — Simple Bilateral Format Handler
 * ============================================================
 * Handles the "simple_bilateral" format family per Loader Spec
 * Table B3: one standing tariff_rates row per line (no phasing,
 * no negative-list, no multi-country split). Applies to Iran,
 * Sri Lanka, Malaysia, Azerbaijan, Indonesia, Türkiye PTA's
 * (partial, see its config notes), Uzbekistan (also uses this
 * as a base before selective_concession specifics are added).
 *
 * RE-RUNNABLE: wipes this agreement's existing tariff_lines
 * (which cascades to tariff_rates via FK) before inserting fresh,
 * per Loader Spec B8 — so re-running after an Excel edit is safe.
 * ============================================================
 */
final class SimpleBilateralHandler
{
    private PDO $pdo;
    private array $config;
    private string $moduleCode;
    private ExceptionsLog $exceptions;

    private int $agreementId;
    /** @var array<string,int> country name (canonical) => countries.id */
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
            'agreement_id'      => $this->agreementId,
            'lines_loaded'      => $totalLoaded,
            'exceptions_count'  => $this->exceptions->count(),
            'exceptions_path'   => $this->exceptions->path(),
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
                    short_name = VALUES(short_name),
                    full_name = VALUES(full_name),
                    source_workbook = VALUES(source_workbook),
                    list_type = VALUES(list_type),
                    coverage = VALUES(coverage),
                    staging = VALUES(staging),
                    anniversary_month = VALUES(anniversary_month),
                    anniversary_day = VALUES(anniversary_day),
                    entry_into_force = VALUES(entry_into_force),
                    staging_horizon_yrs = VALUES(staging_horizon_yrs),
                    default_ceiling_pct = VALUES(default_ceiling_pct),
                    status = VALUES(status),
                    id = LAST_INSERT_ID(id)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':code'                 => $a['code'],
            ':short_name'           => $a['short_name'],
            ':full_name'            => $a['full_name'],
            ':type'                 => $a['type'],
            ':source_workbook'      => $a['source_workbook'],
            ':list_type'            => $a['list_type'],
            ':coverage'             => $a['coverage'],
            ':staging'              => $a['staging'],
            ':anniversary_month'    => $a['anniversary_month'],
            ':anniversary_day'      => $a['anniversary_day'],
            ':entry_into_force'     => $a['entry_into_force'],
            ':staging_horizon_yrs'  => $a['staging_horizon_yrs'],
            ':default_ceiling_pct'  => $a['default_ceiling_pct'],
            ':status'               => $a['status'] ?? 'in_force',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function upsertMembers(): void
    {
        $countryNames = [];
        foreach ($this->config['tariff_sheets'] as $sheet) {
            if (!empty($sheet['import_country'])) {
                $countryNames[] = $sheet['import_country'];
            }
        }
        $countryNames = array_unique($countryNames);

        $sql = "INSERT INTO agreement_members (agreement_id, country_id, role, status)
                VALUES (:agreement_id, :country_id, :role, 'implemented')
                ON DUPLICATE KEY UPDATE role = VALUES(role)";
        $stmt = $this->pdo->prepare($sql);

        foreach ($countryNames as $name) {
            $countryId = $this->resolveCountryId($name);
            if ($countryId === null) {
                continue; // logged inside resolveCountryId
            }
            $stmt->execute([
                ':agreement_id' => $this->agreementId,
                ':country_id'   => $countryId,
                ':role'         => 'party',
            ]);
        }
    }

    private function wipeExistingTariffLines(): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM tariff_lines WHERE agreement_id = :id");
        $stmt->execute([':id' => $this->agreementId]);
    }

    private function loadSheet(array $sheetConfig): int
    {
        $filePath = __DIR__ . '/../../data/AGREEMENT_MODULES_29/' . $this->config['agreement']['source_workbook'];

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

        $importCountryId = $this->resolveCountryId($sheetConfig['import_country']);

        $insertLine = $this->pdo->prepare(
            "INSERT INTO tariff_lines
                (agreement_id, import_country_id, hs_code_raw, hs_code_norm, hs_digits, hs6,
                 product_desc, mfn_kind, mfn_value, mfn_text, mfn_meaning, remarks, source_reference)
             VALUES
                (:agreement_id, :import_country_id, :hs_code_raw, :hs_code_norm, :hs_digits, :hs6,
                 :product_desc, :mfn_kind, :mfn_value, :mfn_text, 'base_at_negotiation', :remarks, :source_reference)"
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
                continue; // genuinely blank row, not an error
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
            $remarks     = $cols['remarks'] ? (string) $sheet->getCell($cols['remarks'] . $row)->getValue() : null;
            $sourceRef   = $cols['source_reference'] ? (string) $sheet->getCell($cols['source_reference'] . $row)->getValue() : null;

            $decimalFraction = $this->config['rate_parsing']['decimal_fraction_convention'] ?? false;

            $mfnParsed = RateParser::parse($mfnCellRaw, $decimalFraction);
            $prefParsed = RateParser::parse($prefCellRaw, $decimalFraction);
            $advParsed = RateParser::parse($advCellRaw, $decimalFraction);

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
                ':remarks'           => $remarks ?: null,
                ':source_reference'  => $sourceRef ?: null,
            ]);

            $lineId = (int) $this->pdo->lastInsertId();

            $insertRate->execute([
                ':tariff_line_id'       => $lineId,
                ':rate_kind'            => $prefParsed['rate_kind'],
                ':rate_value'           => $prefParsed['rate_value'],
                ':rate_text'            => $prefParsed['rate_text'],
                ':effective_advalorem'  => $prefParsed['effective_advalorem'],
                ':advantage_value'      => $advParsed['rate_value'],
                ':advantage_text'       => $advParsed['rate_text'],
            ]);

            $loaded++;
        }

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
