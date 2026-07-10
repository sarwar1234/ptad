<?php

declare(strict_types=1);

namespace Ptad\Api;

use PDO;

/**
 * ============================================================
 * PTAD — Coverage Rule Checker
 * ============================================================
 * Gap A2 (SRS FR-20): checks an HS code against the agreement's
 * coverage_rules (chapter-level include/exclude, e.g. Russia's
 * "Chapter 02, except 0203 and 0207") and flags any line whose
 * OWN stated eligibility (the "Eligible for Pakistan..." /
 * "Not eligible..." text already captured in remarks) DISAGREES
 * with what the coverage rules say — for staff review, per
 * FR-20's explicit requirement.
 *
 * MATCHING LOGIC: the most SPECIFIC matching rule wins (longest
 * hs_prefix that is a prefix of the searched code) — confirmed
 * correct by direct inspection: Russia's chapter "02" is
 * "include", but the more specific "0203"/"0207" are "exclude",
 * meaning a product under 0203 is NOT covered even though its
 * parent chapter generally is.
 * ============================================================
 */
final class CoverageRuleChecker
{
    /**
     * @return array{covered: ?bool, matched_rule: ?string, disagreement: bool}
     *   covered: true/false if a rule matched, null if no rule at all exists for this code
     *   matched_rule: the hs_prefix that decided it, or null
     *   disagreement: true if the line's own stated eligibility contradicts the rule
     */
    public static function check(PDO $pdo, int $agreementId, string $hsCodeNorm, ?string $statedEligibilityText): array
    {
        $stmt = $pdo->prepare("
            SELECT hs_prefix, hs_prefix_len, rule_effect
            FROM coverage_rules
            WHERE agreement_id = :agreement_id
              AND :hs_code LIKE CONCAT(hs_prefix, '%')
            ORDER BY hs_prefix_len DESC
            LIMIT 1
        ");
        $stmt->execute([':agreement_id' => $agreementId, ':hs_code' => $hsCodeNorm]);
        $rule = $stmt->fetch();

        if ($rule === false) {
            return ['covered' => null, 'matched_rule' => null, 'disagreement' => false];
        }

        $covered = $rule['rule_effect'] === 'include';

        // Compare against the line's own stated eligibility text
        // (already captured in remarks via the unmapped-column
        // policy) — disagreement means the rule and the sheet's own
        // stated flag point in different directions, worth flagging
        // for staff review per FR-20, rather than silently trusting
        // one over the other.
        $disagreement = false;
        if ($statedEligibilityText !== null) {
            $statedEligible = str_contains($statedEligibilityText, 'Eligible for Pakistan');
            $statedNotEligible = str_contains($statedEligibilityText, 'Not eligible');

            if (($statedEligible && !$covered) || ($statedNotEligible && $covered)) {
                $disagreement = true;
            }
        }

        return [
            'covered'      => $covered,
            'matched_rule' => $rule['hs_prefix'],
            'disagreement' => $disagreement,
        ];
    }
}
