<?php

declare(strict_types=1);

namespace PtadLoader;

use PDO;
use Ptad\Database\Connection;

/**
 * ============================================================
 * PTAD — Quota/Season Note Extractor
 * ============================================================
 * Gap #3 fix: quota_note/season_note columns were confirmed EMPTY
 * across all 213,000+ loaded tariff_rates rows, even though real
 * quota/seasonal conditions clearly exist in tariff_lines.remarks
 * (Iran's rice quota, Sri Lanka's basmati TRQ, Mauritius's apparel
 * piece-count quotas — all confirmed by direct inspection).
 *
 * This is a POST-PROCESSING pass, run after --all / --module,
 * since it operates on already-loaded remarks text rather than
 * reading the source Excel files directly — the same free-text
 * quota language can appear differently per module, so this scans
 * the already-normalized database text.
 *
 * EXTRACTION PHILOSOPHY: extracts the exact matched clause
 * VERBATIM from remarks (never re-synthesizes or re-states a
 * quantity in its own words) — consistent with this project's
 * "never fabricate, point to the real text" rule. quota_note and
 * season_note hold the precise substring that describes the
 * condition, not a parsed/derived number.
 * ============================================================
 */
final class QuotaNoteExtractor
{
    private PDO $pdo;

    /**
     * Patterns confirmed by direct inspection of real remarks text
     * across Iran, Sri Lanka, and Mauritius modules. Each captures
     * the full descriptive clause (not just a bare number) so the
     * stored quota_note is self-explanatory on its own.
     */
    private const QUOTA_PATTERNS = [
        // "10.5% reduction up to 100,000 MT annually."
        '/\b[\d.,]+\s*MT annually\b[^.]*\.?/i',
        // "6,000 MT/year (Jan-Dec)" / "1,000 MT/year"
        '/\b[\d,]+\s*MT\/year[^;.]*/i',
        // "10,000 MT in one financial year"
        '/\b[\d,]+\s*MT in one financial year\b/i',
        // "3,000,000 pieces per financial year across listed 24 tariff lines, max 200,000 pieces per tariff line"
        '/\b[\d,]+\s*pieces per financial year[^;.]*/i',
        // "tariff-rate quota of 300,000 pieces"
        '/\btariff-rate quota of [\d,]+\s*pieces\b/i',
    ];

    /**
     * Seasonal-split patterns — confirmed real case: Sri Lanka's
     * basmati rice line splits its quota across two named periods.
     */
    private const SEASON_PATTERNS = [
        '/\b\d\/\d\s+during\s+[A-Za-z]+-[A-Za-z]+\s+and\s+\d\/\d\s+during\s+[A-Za-z]+-[A-Za-z]+\b/i',
        '/\([A-Za-z]{3}-[A-Za-z]{3}\)/', // e.g. "(Jan-Dec)"
    ];

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /**
     * Scans every tariff_line with non-empty remarks, and for any
     * quota/season pattern found, updates ALL of that line's
     * tariff_rates rows (a line can have multiple rate rows —
     * e.g. LDC/non-LDC, or multiple years — the quota condition
     * described in remarks applies to the whole line, not just one
     * specific rate row).
     */
    public function run(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, remarks FROM tariff_lines
            WHERE remarks IS NOT NULL AND remarks != ''
        ");

        $quotaMatches = 0;
        $seasonMatches = 0;
        $linesUpdated = 0;

        $updateStmt = $this->pdo->prepare("
            UPDATE tariff_rates
            SET quota_note = :quota_note, season_note = :season_note
            WHERE tariff_line_id = :line_id
        ");

        while ($line = $stmt->fetch()) {
            $quotaNote = $this->extractFirst(self::QUOTA_PATTERNS, $line['remarks']);
            $seasonNote = $this->extractFirst(self::SEASON_PATTERNS, $line['remarks']);

            if ($quotaNote === null && $seasonNote === null) {
                continue;
            }

            $updateStmt->execute([
                ':quota_note'  => $quotaNote,
                ':season_note' => $seasonNote,
                ':line_id'     => $line['id'],
            ]);

            $linesUpdated++;
            if ($quotaNote !== null) $quotaMatches++;
            if ($seasonNote !== null) $seasonMatches++;
        }

        return [
            'lines_updated'  => $linesUpdated,
            'quota_matches'  => $quotaMatches,
            'season_matches' => $seasonMatches,
        ];
    }

    private function extractFirst(array $patterns, string $text): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return trim($m[0]);
            }
        }
        return null;
    }
}
