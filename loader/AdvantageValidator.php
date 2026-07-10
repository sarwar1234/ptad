<?php

declare(strict_types=1);

namespace PtadLoader;

use PDO;
use Ptad\Database\Connection;

/**
 * ============================================================
 * PTAD — Advantage Validator
 * ============================================================
 * Gap A1: Document B requires the loader to "re-check each
 * advantage against the two rates and flag any mismatch."
 *
 * IMPORTANT — floor-at-zero is CORRECT, not a mismatch: when the
 * current MFN rate is actually lower than the notified
 * preferential rate (confirmed real case: Indonesia, 68 lines,
 * e.g. MFN 20% vs a 24% "preferential" rate left over from an
 * older schedule), the source data correctly shows 0% advantage
 * rather than a nonsensical negative number. This validator
 * accounts for that rule and does NOT flag it.
 *
 * GENUINE mismatches found during investigation (Malaysia,
 * Sri Lanka): some cells store values as decimal fractions
 * (0.35 meaning 35%) INCONSISTENTLY mixed with normal percentages
 * within the same column — unlike Mauritius's clean, uniform
 * fraction convention. There is no safe way to auto-correct these
 * without guessing which convention a specific cell used, so this
 * validator FLAGS them for manual review rather than attempting
 * an automatic fix, per the project's "never fabricate when
 * genuinely ambiguous" rule.
 * ============================================================
 */
final class AdvantageValidator
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /**
     * @return array<int, array> Each row is a genuine, unexplained mismatch.
     */
    public function findMismatches(): array
    {
        $sql = "
            SELECT
                a.code AS agreement_code,
                tl.hs_code_raw,
                tl.mfn_value,
                tr.rate_value,
                tr.advantage_value,
                (tl.mfn_value - tr.rate_value) AS expected_advantage,
                tl.remarks
            FROM tariff_lines tl
            JOIN agreements a ON a.id = tl.agreement_id
            JOIN tariff_rates tr ON tr.tariff_line_id = tl.id
            WHERE tl.mfn_value IS NOT NULL
              AND tr.rate_value IS NOT NULL
              AND tr.advantage_value IS NOT NULL
              AND ABS((tl.mfn_value - tr.rate_value) - tr.advantage_value) > 0.01
              -- Exclude the legitimate floor-at-zero case: when the
              -- naive subtraction would be negative (rate_value >
              -- mfn_value) AND the source's own advantage_value is
              -- already correctly 0 — that's the source doing the
              -- right thing, not an error.
              AND NOT (tr.rate_value > tl.mfn_value AND tr.advantage_value = 0)
            ORDER BY a.code, tl.hs_code_raw
        ";

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }

    public function report(): void
    {
        $mismatches = $this->findMismatches();

        if (empty($mismatches)) {
            echo "No unexplained advantage mismatches found.\n";
            return;
        }

        echo "Found " . count($mismatches) . " unexplained advantage mismatch(es) needing manual review:\n\n";
        $byModule = [];
        foreach ($mismatches as $m) {
            $byModule[$m['agreement_code']][] = $m;
        }

        foreach ($byModule as $code => $rows) {
            echo "--- {$code} (" . count($rows) . " rows) ---\n";
            foreach (array_slice($rows, 0, 3) as $r) {
                echo "  {$r['hs_code_raw']}: MFN={$r['mfn_value']} Pref={$r['rate_value']} " .
                     "StoredAdv={$r['advantage_value']} Expected={$r['expected_advantage']}\n";
            }
            if (count($rows) > 3) {
                echo "  ... and " . (count($rows) - 3) . " more\n";
            }
        }

        echo "\nThese are NOT auto-corrected — the source data's own convention (percentage vs\n";
        echo "decimal-fraction) is inconsistent within these specific rows/modules, and guessing\n";
        echo "which one applies risks fabricating a wrong number. Please review with a trade\n";
        echo "expert against the original Excel source before deciding on a fix.\n";
    }
}
