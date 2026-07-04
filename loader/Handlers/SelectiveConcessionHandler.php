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
 * PTAD — Selective Concession Format Handler
 * ============================================================
 * Handles the "selective_concession" format family — confirmed
 * (Uzbekistan PTA) to have THREE distinct text fields per line,
 * not a plain rate:
 *
 *   Base_Rate:          "CD 20%, but not less than USD 0.20/kg"
 *   Preferential_Rate:  "20% decrease in CD"   (the MECHANISM)
 *   Tariff_Advantage:   "CD 16%, but not less than USD 0.16/kg" (OUTCOME)
 *
 * Per schema Section 5 ("concession mechanics", v1.2): this maps to
 * tariff_rates.base_value/base_text (from Base_Rate),
 * concession_type/concession_pct (from Preferential_Rate),
 * outcome_value/outcome_text (from Tariff_Advantage) — rather than
 * treating Preferential_Rate as a plain rate_value the way every
 * other handler does, since "20% decrease in CD" is a MECHANISM,
 * not a rate.
 *
 * Verified by direct arithmetic against real rows before building
 * this (confirms the source data is internally consistent):
 *   20% base, 20% decrease -> 16% outcome  (20 * (1-0.20) = 16)  ✓
 *    5% base, 100% decrease ->  0% outcome  ( 5 * (1-1.00) =  0)  ✓
 *   20% base, 30% decrease -> 14% outcome  (20 * (1-0.30) = 14)  ✓
 * ============================================================
 */
final class SelectiveConcessionHandler
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
    }

    private function loadSheet(array $sheetConfig): int
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
        $importCountryId = $this->resolveCountryId($sheetConfig['import_country']);

        $insertLine = $this->pdo->prepare(
            "INSERT INTO tariff_lines
                (agreement_id, import_country_id, hs_code_raw, hs_code_norm, hs_digits, hs6,
                 product_desc, mfn_kind, mfn_text, mfn_meaning, remarks, source_reference)
             VALUES
                (:agreement_id, :import_country_id, :hs_code_raw, :hs_code_norm, :hs_digits, :hs6,
                 :product_desc, 'compound', :mfn_text, 'base_at_negotiation', :remarks, :source_reference)"
        );

        $insertRate = $this->pdo->prepare(
            "INSERT INTO tariff_rates
                (tariff_line_id, `condition`, component, rate_kind, rate_value, rate_text, effective_advalorem,
                 base_value, base_text, concession_type, concession_pct, specific_floor_text,
                 outcome_value, outcome_text)
             VALUES
                (:tariff_line_id, 'standard', :component, 'compound', :rate_value, :rate_text, :effective_advalorem,
                 :base_value, :base_text, :concession_type, :concession_pct, :specific_floor_text,
                 :outcome_value, :outcome_text)"
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

            $description = (string) $sheet->getCell($cols['description'] . $row)->getValue();
            $baseRateText = (string) $sheet->getCell($cols['mfn_rate'] . $row)->getValue();
            $concessionText = (string) $sheet->getCell($cols['preferential_rate'] . $row)->getValue();
            $outcomeText = (string) $sheet->getCell($cols['tariff_advantage'] . $row)->getValue();
            $remarks = $cols['remarks'] ? (string) $sheet->getCell($cols['remarks'] . $row)->getValue() : null;
            $sourceRef = $cols['source_reference'] ? (string) $sheet->getCell($cols['source_reference'] . $row)->getValue() : null;

            $insertLine->execute([
                ':agreement_id'      => $this->agreementId,
                ':import_country_id' => $importCountryId,
                ':hs_code_raw'       => $hs['raw'],
                ':hs_code_norm'      => $hs['norm'],
                ':hs_digits'         => $hs['digits'],
                ':hs6'               => $hs['hs6'],
                ':product_desc'      => $description,
                ':mfn_text'          => $baseRateText ?: null,
                ':remarks'           => $remarks ?: null,
                ':source_reference'  => $sourceRef ?: null,
            ]);
            $lineId = (int) $this->pdo->lastInsertId();

            // Per-component concessions (confirmed on the "Imports into
            // Pakistan" sheet: cells like "50% exemption on CD + 100%
            // exemption on ACD + 25% exemption on RD" describe UP TO
            // THREE separate concessions in one cell, one per duty
            // component). Each becomes its own tariff_rates row with
            // the matching component enum value, rather than forcing
            // a single combined row that would lose which percentage
            // applies to which component.
            $components = $this->parseComponentConcessions($concessionText, $baseRateText, $outcomeText);

            if (empty($components)) {
                $this->exceptions->record($sheetConfig['sheet_name'], $row, $hsCodeRaw, "Could not parse concession mechanism from: \"{$concessionText}\"");
            }

            foreach ($components as $comp) {
                $insertRate->execute([
                    ':tariff_line_id'      => $lineId,
                    ':component'           => $comp['component'],
                    ':rate_value'          => $comp['outcome_value'],
                    ':rate_text'           => $outcomeText ?: null,
                    ':effective_advalorem' => $comp['outcome_value'],
                    ':base_value'          => $comp['base_value'],
                    ':base_text'           => $baseRateText ?: null,
                    ':concession_type'     => $comp['concession_type'],
                    ':concession_pct'      => $comp['concession_pct'],
                    ':specific_floor_text' => $comp['specific_floor_text'],
                    ':outcome_value'       => $comp['outcome_value'],
                    ':outcome_text'        => $outcomeText ?: null,
                ]);
            }

            // When the outcome text gives a single COMBINED figure
            // rather than a per-component breakdown (confirmed: "40%
            // combined duty after PTA" on multi-component lines), that
            // combined number is real data and must still be captured
            // — but attaching it to each individual component row
            // would falsely imply each component alone produces that
            // outcome. It gets its own component='total' row instead.
            if (count($components) > 1 && !$this->outcomeNamesEachComponent($outcomeText, $components)) {
                if (preg_match('/(\d+(?:\.\d+)?)\s*%/', $outcomeText, $om)) {
                    $insertRate->execute([
                        ':tariff_line_id'      => $lineId,
                        ':component'           => 'total',
                        ':rate_value'          => (float) $om[1],
                        ':rate_text'           => $outcomeText ?: null,
                        ':effective_advalorem' => (float) $om[1],
                        ':base_value'          => null,
                        ':base_text'           => $baseRateText ?: null,
                        ':concession_type'     => null,
                        ':concession_pct'      => null,
                        ':specific_floor_text' => null,
                        ':outcome_value'       => (float) $om[1],
                        ':outcome_text'        => $outcomeText ?: null,
                    ]);
                }
            }

            $loaded++;
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $sheet);
        gc_collect_cycles();

        return $loaded;
    }

    /**
     * Parses the concession text into one entry PER DUTY COMPONENT.
     *
     * Handles both wording conventions confirmed in the real file:
     *   - "X% decrease in CD"                          (Uzbekistan-side sheet, single component)
     *   - "X% exemption on CD"                          (Pakistan-side sheet, single component)
     *   - "X% exemption on CD + Y% exemption on ACD..." (Pakistan-side, MULTIPLE components in one cell)
     *
     * The outcome text can likewise mention multiple components
     * ("CD 16%, ACD 0%, ..."); this method matches each component's
     * outcome percentage independently rather than assuming there's
     * only one number to find.
     *
     * @return array<int, array{component:string, base_value:?float,
     *   concession_type:?string, concession_pct:?float,
     *   specific_floor_text:?string, outcome_value:?float}>
     */
    private function parseComponentConcessions(string $concessionText, string $baseText, string $outcomeText): array
    {
        $results = [];

        // Specific floor clause on the base rate ("but not less than
        // USD X/kg"), shared across whichever component(s) this line has.
        $specificFloor = null;
        if (preg_match('/but not less than\s+(.+)$/i', $baseText, $m)) {
            $specificFloor = trim($m[1]);
        }

        // A cell can describe several components joined by "+". Split
        // and parse each piece independently.
        $pieces = array_map('trim', explode('+', $concessionText));
        $isMultiComponent = count($pieces) > 1;

        foreach ($pieces as $piece) {
            if (!preg_match('/(\d+(?:\.\d+)?)\s*%\s*(?:decrease in|exemption on)\s*(CD|ACD|RD)/i', $piece, $m)) {
                continue; // unparseable piece; overall empty-result handling logs this at the call site
            }

            $pct = (float) $m[1];
            $componentCode = strtolower($m[2]); // 'cd' | 'acd' | 'rd' — matches schema's component ENUM

            // Base value for THIS specific component: look for
            // "<component> X%" in the base text (e.g. base text may
            // itself list multiple components on the Pakistan sheet;
            // on the Uzbekistan sheet there's usually just one "CD X%").
            $baseValue = null;
            if (preg_match('/' . preg_quote($m[2], '/') . '\s*(\d+(?:\.\d+)?)\s*%/i', $baseText, $bm)) {
                $baseValue = (float) $bm[1];
            } elseif (!$isMultiComponent && preg_match('/(\d+(?:\.\d+)?)\s*%/', $baseText, $bm)) {
                // Fallback only valid for a genuinely single-component
                // line — safe to assume the one number in the base
                // text belongs to the one component being described.
                $baseValue = (float) $bm[1];
            }

            // Outcome value for THIS component: ONLY set it when the
            // outcome text explicitly names this component. Do NOT
            // fall back to "the first bare percentage found" when
            // there are multiple components in the cell — confirmed
            // that produces a false result (e.g. "40% combined duty
            // after PTA" is a TOTAL, not each component's individual
            // outcome; assigning it to all three would misrepresent
            // the data). The combined figure is captured separately
            // as a 'total' component row by the caller instead.
            $outcomeValue = null;
            if (preg_match('/' . preg_quote($m[2], '/') . '\s*(\d+(?:\.\d+)?)\s*%/i', $outcomeText, $om)) {
                $outcomeValue = (float) $om[1];
            } elseif (!$isMultiComponent && preg_match('/(\d+(?:\.\d+)?)\s*%/', $outcomeText, $om)) {
                $outcomeValue = (float) $om[1];
            }

            $results[] = [
                'component'           => $componentCode,
                'base_value'          => $baseValue,
                'concession_type'     => $pct >= 100.0 ? 'full_exemption' : 'reduction_pct',
                'concession_pct'      => $pct,
                'specific_floor_text' => $specificFloor,
                'outcome_value'       => $outcomeValue,
            ];
        }

        return $results;
    }

    /**
     * True only if the outcome text explicitly names EVERY component
     * present in $components (e.g. "CD 16%, ACD 0%, RD 30%") — meaning
     * it's safe to trust each component row's individually-matched
     * outcome_value. False when it's a single combined figure like
     * "40% combined duty after PTA", which doesn't mention any
     * component by name at all.
     */
    private function outcomeNamesEachComponent(string $outcomeText, array $components): bool
    {
        foreach ($components as $comp) {
            if (!preg_match('/\b' . strtoupper($comp['component']) . '\b/i', $outcomeText)) {
                return false;
            }
        }
        return true;
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
