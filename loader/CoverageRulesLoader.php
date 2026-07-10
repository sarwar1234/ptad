<?php

declare(strict_types=1);

namespace PtadLoader;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Ptad\Database\Connection;
use Ptad\Helpers\HsCode;
use PtadLoader\Support\ExceptionsLog;

/**
 * ============================================================
 * PTAD — Coverage Rules Loader (EAEU modules only)
 * ============================================================
 * Loads the "Eligible Goods List" sheet into coverage_rules —
 * confirmed by direct inspection to exist ONLY in the 5 EAEU
 * modules (Russia, Armenia, Belarus, Kazakhstan, Kyrgyzstan),
 * each an identical structure/template. Türkiye GSP mentions
 * chapter exclusions only as free caution text in its Tariff
 * Preferences sheet, NOT as structured data — there is nothing
 * to load into coverage_rules for Türkiye; that module's
 * exclusion caveat remains a guidance-engine text note, not
 * structured coverage data, since fabricating structure from
 * unstructured prose would risk misrepresenting the source.
 *
 * PARSING LOGIC: the "Commodity Code / HS Coverage" column holds
 * entries like:
 *   "02 (except 0203, 0207)"                    -> one INCLUDE (ch.02),
 *                                                   two EXCLUDEs (0203, 0207)
 *   "4403 41 000 0, 4403 42 000 0, 4403 49"       -> three separate INCLUDEs
 *     (comma-separated codes with no "except" clause)
 *
 * Each row of the source sheet can therefore produce MULTIPLE
 * coverage_rules rows — one list_no (the sheet's own "No." column)
 * groups them, so all rules from one source row can be traced
 * back to the same original list entry.
 * ============================================================
 */
final class CoverageRulesLoader
{
    private PDO $pdo;
    private ExceptionsLog $exceptions;

    private const EAEU_MODULE_CODES = ['GSP_RUS', 'GSP_ARM', 'GSP_BLR', 'GSP_KAZ', 'GSP_KGZ'];

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->exceptions = new ExceptionsLog('coverage_rules');
    }

    public function loadAll(): array
    {
        $stmt = $this->pdo->prepare("SELECT id, source_workbook FROM agreements WHERE code = :code LIMIT 1");
        $totalRules = 0;
        $results = [];

        foreach (self::EAEU_MODULE_CODES as $code) {
            $stmt->execute([':code' => $code]);
            $agreement = $stmt->fetch();

            if ($agreement === false) {
                $results[$code] = 'agreement not found — run --all first';
                continue;
            }

            $count = $this->loadForModule($code, $agreement['source_workbook'], (int) $agreement['id']);
            $results[$code] = "{$count} rules loaded";
            $totalRules += $count;
        }

        return ['per_module' => $results, 'total' => $totalRules];
    }

    private function loadForModule(string $moduleCode, string $sourceWorkbook, int $agreementId): int
    {
        $filePath = __DIR__ . '/../data/AGREEMENT_MODULES_29/' . $sourceWorkbook;

        if (!file_exists($filePath)) {
            throw new \RuntimeException("Workbook not found: {$filePath}");
        }

        $this->pdo->prepare("DELETE FROM coverage_rules WHERE agreement_id = :id")->execute([':id' => $agreementId]);

        $spreadsheet = IOFactory::createReaderForFile($filePath)->load($filePath);
        $sheet = $spreadsheet->getSheetByName('Eligible Goods List');

        if ($sheet === null) {
            $this->exceptions->record($moduleCode, 0, '', "No 'Eligible Goods List' sheet found.");
            return 0;
        }

        // Header confirmed at row 9 by direct inspection across all
        // 5 EAEU workbooks (identical template).
        $headerRow = 9;
        $highestRow = $sheet->getHighestRow();

        $insertStmt = $this->pdo->prepare("
            INSERT INTO coverage_rules
                (agreement_id, list_no, hs_prefix, hs_prefix_len, rule_effect,
                 raw_coverage, description, beneficiary_scope, user_note)
            VALUES
                (:agreement_id, :list_no, :hs_prefix, :hs_prefix_len, :rule_effect,
                 :raw_coverage, :description, :beneficiary_scope, :user_note)
        ");

        $loaded = 0;

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $listNo = $sheet->getCell('A' . $row)->getValue();
            $coverageRaw = trim((string) $sheet->getCell('B' . $row)->getValue());

            if ($coverageRaw === '') {
                continue;
            }

            $description = (string) $sheet->getCell('C' . $row)->getValue();
            $beneficiaryScope = (string) $sheet->getCell('D' . $row)->getValue();
            $userNote = (string) $sheet->getCell('E' . $row)->getValue();

            $parsed = $this->parseCoverageCell($coverageRaw);

            foreach ($parsed as $rule) {
                $insertStmt->execute([
                    ':agreement_id'      => $agreementId,
                    ':list_no'           => $listNo,
                    ':hs_prefix'         => $rule['hs_prefix'],
                    ':hs_prefix_len'     => strlen($rule['hs_prefix']),
                    ':rule_effect'       => $rule['rule_effect'],
                    ':raw_coverage'      => $coverageRaw,
                    ':description'       => $description ?: null,
                    ':beneficiary_scope' => $beneficiaryScope ?: null,
                    ':user_note'         => $userNote ?: null,
                ]);
                $loaded++;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $sheet);
        gc_collect_cycles();

        return $loaded;
    }

    /**
     * Parses one "Commodity Code / HS Coverage" cell into one or more
     * include/exclude rules.
     *
     * @return array<int, array{hs_prefix: string, rule_effect: string}>
     */
    private function parseCoverageCell(string $raw): array
    {
        $rules = [];

        // Split off an "(except X, Y, ...)" clause if present.
        $exceptCodes = [];
        $mainPart = $raw;

        if (preg_match('/^(.*?)\s*\(except\s+(.+?)\)\s*$/i', $raw, $m)) {
            $mainPart = trim($m[1]);
            $exceptCodes = array_map('trim', explode(',', $m[2]));
        }

        // The main part may itself be multiple comma-separated codes
        // (confirmed real case: "4403 41 000 0, 4403 42 000 0, 4403 49").
        foreach (explode(',', $mainPart) as $code) {
            $norm = HsCode::normalize($code)['norm'];
            if ($norm !== '' && HsCode::isPlausible($norm)) {
                $rules[] = ['hs_prefix' => $norm, 'rule_effect' => 'include'];
            }
        }

        foreach ($exceptCodes as $code) {
            $norm = HsCode::normalize($code)['norm'];
            if ($norm !== '' && HsCode::isPlausible($norm)) {
                $rules[] = ['hs_prefix' => $norm, 'rule_effect' => 'exclude'];
            }
        }

        return $rules;
    }

    public function closeLog(): void
    {
        $this->exceptions->close();
    }
}
