-- ============================================================================
-- PTAD — Migration: staging_remarks table (Gap B1)
-- ----------------------------------------------------------------------------
-- Document A explicitly requires: "store each DISTINCT remark once and
-- link every line sharing it to that stored sentence." Confirmed real
-- scale of the problem: China has 15,235 tariff_lines rows but only 22
-- distinct remark values — currently the same text is repeated on
-- every single row.
--
-- DESIGN: tariff_lines KEEPS its own `remarks` column for genuinely
-- line-specific text (the rare 1x notes Document A explicitly says
-- must stay on their line, since they were never duplicates). A NEW
-- `staging_remark_id` foreign key is added for the shared/repeated
-- staging text — set for phased-agreement lines, NULL for lines with
-- only a genuinely unique remark.
-- ============================================================================

CREATE TABLE IF NOT EXISTS staging_remarks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agreement_id    SMALLINT UNSIGNED NOT NULL,
    remark_text     TEXT NOT NULL,
    -- MySQL cannot UNIQUE-index a TEXT column directly without a
    -- prefix length; a generated, indexed hash column gives an exact
    -- per-agreement uniqueness guarantee without truncating the text
    -- itself (some remarks exceed a safe prefix-index length).
    remark_hash     CHAR(64) GENERATED ALWAYS AS (SHA2(remark_text, 256)) STORED,
    UNIQUE KEY uq_agreement_remark_hash (agreement_id, remark_hash),
    CONSTRAINT fk_staging_remarks_agreement FOREIGN KEY (agreement_id)
        REFERENCES agreements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tariff_lines
    ADD COLUMN staging_remark_id INT UNSIGNED NULL AFTER remarks,
    ADD CONSTRAINT fk_lines_staging_remark FOREIGN KEY (staging_remark_id)
        REFERENCES staging_remarks(id);

CREATE INDEX idx_lines_staging_remark ON tariff_lines (staging_remark_id);
