<?php

declare(strict_types=1);

namespace PtadLoader\Handlers;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Ptad\Database\Connection;
use Ptad\Helpers\HsCode;
use PtadLoader\Support\ExceptionsLog;

/**
 * ============================================================
 * PTAD — Negative List Format Handler (SAFTA)
 * ============================================================
 * Handles the "negative_list" format family — SAFTA, the ONE
 * module in the entire system with INVERTED eligibility logic,
 * and flagged throughout the project documents as the
 * highest-risk item to get right:
 *
 *   - Every row in these sheets is a SENSITIVE/EXCLUDED product
 *     (confirmed: literally every row across all 8 member sheets
 *     reads exactly "Sensitive / Excluded" — no exceptions found).
 *   - A product LISTED here => is_excluded = TRUE, NO concession.
 *   - A product NOT LISTED here => eligible for SAFTA's default
 *     ceiling (agreements.default_ceiling_pct, set to 5.0 in this
 *     module's config), computed AT QUERY TIME by the API layer —
 *     NOT by this loader. This loader only ever writes rows for
 *     the EXCLUDED products; it must NEVER attempt to enumerate
 *     or fabricate "eligible" rows for codes that aren't listed,
 *     since that set is effectively every other HS code and isn't
 *     something the source data enumerates.
 *
 * Per Loader Spec: "Load listed rows with is_excluded = TRUE;
 * eligibility for non-listed lines is computed at query time
 * from the agreement's ceiling." No tariff_rates row is created
 * for these lines at all — there is no rate to store, only the
 * exclusion fact itself (tariff_lines.is_excluded).
 * ============================================================
 */
final class NegativeListHandler
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

        // list_type MUST be 'negative' for SAFTA — this single flag is
        // what tells the API layer to invert its eligibility logic for
        // this agreement (per Schema Companion Guide: "This one setting
        // makes the search give correct answers for SAFTA without any
        // special programming"). Loaded from config, not hardcoded here,
        // but this handler should ONLY ever be used for a module whose
        // config already has list_type='negative' — verified below as
        // a hard safety check, not just an assumption.
        if (($a['list_type'] ?? null) !== 'negative') {
            throw new \RuntimeException(
                "NegativeListHandler was invoked for module '{$this->moduleCode}' but its config's " .
                "list_type is '{$a['list_type']}', not 'negative'. Refusing to proceed — this handler " .
                "must only ever be used for genuinely inverted-logic modules, since using it by mistake " .
                "would silently mark every product in a normal module as excluded."
            );
        }

        $sql = "INSERT INTO agreements
                    (code, short_name, full_name, type, source_workbook,
                     list_type, coverage, staging, default_ceiling_pct, status)
                VALUES
                    (:code, :short_name, :full_name, :type, :source_workbook,
                     :list_type, :coverage, :staging, :default_ceiling_pct, :status)
                ON DUPLICATE KEY UPDATE
                    short_name = VALUES(short_name), full_name = VALUES(full_name),
                    source_workbook = VALUES(source_workbook), list_type = VALUES(list_type),
                    coverage = VALUES(coverage), default_ceiling_pct = VALUES(default_ceiling_pct),
                    status = VALUES(status), id = LAST_INSERT_ID(id)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':code' => $a['code'], ':short_name' => $a['short_name'], ':full_name' => $a['full_name'],
            ':type' => $a['type'], ':source_workbook' => $a['source_workbook'],
            ':list_type' => $a['list_type'], ':coverage' => $a['coverage'], ':staging' => $a['staging'],
            ':default_ceiling_pct' => $a['default_ceiling_pct'], ':status' => $a['status'] ?? 'in_force',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function upsertMembers(): void
    {
        $sql = "INSERT INTO agreement_members (agreement_id, country_id, role, status, member_ceiling_pct)
                VALUES (:agreement_id, :country_id, 'party', 'implemented', :ceiling)
                ON DUPLICATE KEY UPDATE role = VALUES(role)";
        $stmt = $this->pdo->prepare($sql);

        foreach ($this->config['tariff_sheets'] as $sheet) {
            $countryId = $this->resolveCountryId($sheet['member_country']);
            if ($countryId === null) {
                continue;
            }
            $stmt->execute([
                ':agreement_id' => $this->agreementId,
                ':country_id'   => $countryId,
                ':ceiling'      => $this->config['agreement']['default_ceiling_pct'],
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
        $headerRow = $sheetConfig['header_row'];
        $highestRow = $sheet->getHighestRow();
        $memberCountryId = $this->resolveCountryId($sheetConfig['member_country']);

        $insertLine = $this->pdo->prepare(
            "INSERT INTO tariff_lines
                (agreement_id, member_country_id, hs_code_raw, hs_code_norm, hs_digits, hs6,
                 product_desc, is_excluded, remarks, source_reference)
             VALUES
                (:agreement_id, :member_country_id, :hs_code_raw, :hs_code_norm, :hs_digits, :hs6,
                 :product_desc, :is_excluded, :remarks, :source_reference)"
        );

        $loaded = 0;
        $nonExcludedCount = 0; // tracked for a safety check below

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

            $description = (string) $sheet->getCell($cols['description'] . $row)->getValue();
            $exclusionFlagRaw = trim((string) $sheet->getCell($cols['exclusion_flag'] . $row)->getValue());
            $remarks = $cols['remarks'] ? (string) $sheet->getCell($cols['remarks'] . $row)->getValue() : null;
            $sourceRef = $cols['source_reference'] ? (string) $sheet->getCell($cols['source_reference'] . $row)->getValue() : null;

            // Every row in this sheet family represents an EXCLUDED
            // product by definition (confirmed: 100% of rows across
            // all 8 real member sheets read exactly "Sensitive /
            // Excluded", verified by direct inspection before writing
            // this handler). is_excluded is set true for any non-empty
            // flag value — matching the confirmed real-world content —
            // rather than string-matching one exact phrase, so the
            // loader doesn't silently mis-load a row if a future update
            // to the workbook rewords the flag text slightly.
            $isExcluded = $exclusionFlagRaw !== '';

            if (!$isExcluded) {
                $nonExcludedCount++;
            }

            $insertLine->execute([
                ':agreement_id'      => $this->agreementId,
                ':member_country_id' => $memberCountryId,
                ':hs_code_raw'       => $hs['raw'],
                ':hs_code_norm'      => $hs['norm'],
                ':hs_digits'         => $hs['digits'],
                ':hs6'               => $hs['hs6'],
                ':product_desc'      => $description,
                ':is_excluded'       => $isExcluded ? 1 : 0,
                ':remarks'           => $remarks ?: null,
                ':source_reference'  => $sourceRef ?: null,
            ]);

            $loaded++;
        }

        // Safety check: per the design, this sheet family should be
        // ENTIRELY excluded rows. If even one row comes through with an
        // empty/different flag, that's worth surfacing loudly rather
        // than silently trusting the assumption — it could mean the
        // workbook has since been updated with a genuinely different
        // pattern that this handler needs to be revisited for.
        if ($nonExcludedCount > 0) {
            $this->exceptions->record(
                $sheetConfig['sheet_name'], 0, '',
                "WARNING: {$nonExcludedCount} row(s) in this sheet did NOT have the expected exclusion flag set. " .
                "This module's entire design assumes every listed row is excluded — please review this sheet manually."
            );
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
