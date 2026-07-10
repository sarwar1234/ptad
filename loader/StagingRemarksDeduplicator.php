<?php

declare(strict_types=1);

namespace PtadLoader;

use PDO;
use Ptad\Database\Connection;

/**
 * ============================================================
 * PTAD — Staging Remarks Deduplication (Gap B1)
 * ============================================================
 * One-time (but safely re-runnable) migration: for every phased
 * agreement (staging != 'none'), finds every DISTINCT remarks
 * value currently repeated across many tariff_lines rows, inserts
 * each distinct value once into staging_remarks, links every
 * sharing line via staging_remark_id, and clears the now-redundant
 * tariff_lines.remarks for those linked rows (the text is still
 * fully available via the join — nothing is lost, just no longer
 * duplicated in storage).
 *
 * SAFETY: only touches lines whose remarks value is shared by 2+
 * lines within the same agreement — a genuinely unique, single-line
 * remark is left exactly where Document A says it belongs: directly
 * on tariff_lines.remarks, never moved into the shared table.
 * ============================================================
 */
final class StagingRemarksDeduplicator
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    public function run(): array
    {
        $agreements = $this->pdo->query("
            SELECT id, code FROM agreements WHERE staging != 'none'
        ")->fetchAll();

        $results = [];

        foreach ($agreements as $agreement) {
            $results[$agreement['code']] = $this->deduplicateForAgreement((int) $agreement['id']);
        }

        return $results;
    }

    private function deduplicateForAgreement(int $agreementId): array
    {
        // Find remarks values shared by 2+ lines within this agreement
        // — the actual duplication Document A is concerned with.
        $sharedStmt = $this->pdo->prepare("
            SELECT remarks, COUNT(*) as line_count
            FROM tariff_lines
            WHERE agreement_id = :agreement_id
              AND remarks IS NOT NULL
              AND staging_remark_id IS NULL
            GROUP BY remarks
            HAVING COUNT(*) >= 2
        ");
        $sharedStmt->execute([':agreement_id' => $agreementId]);
        $sharedRemarks = $sharedStmt->fetchAll();

        $insertRemark = $this->pdo->prepare("
            INSERT INTO staging_remarks (agreement_id, remark_text)
            VALUES (:agreement_id, :remark_text)
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
        ");

        $linkLines = $this->pdo->prepare("
            UPDATE tariff_lines
            SET staging_remark_id = :remark_id, remarks = NULL
            WHERE agreement_id = :agreement_id AND remarks = :remark_text
        ");

        $distinctCount = 0;
        $linesLinked = 0;

        foreach ($sharedRemarks as $sr) {
            $insertRemark->execute([
                ':agreement_id' => $agreementId,
                ':remark_text'  => $sr['remarks'],
            ]);
            $remarkId = (int) $this->pdo->lastInsertId();

            $linkLines->execute([
                ':remark_id'    => $remarkId,
                ':agreement_id' => $agreementId,
                ':remark_text'  => $sr['remarks'],
            ]);

            $distinctCount++;
            $linesLinked += (int) $sr['line_count'];
        }

        return ['distinct_remarks' => $distinctCount, 'lines_linked' => $linesLinked];
    }
}
