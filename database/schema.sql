-- ============================================================================
-- PTAD — Preferential Trade Access Database (Pakistan)
-- MySQL 8 Schema  •  Converted from PostgreSQL Schema v1.4 (FINAL)
-- ----------------------------------------------------------------------------
-- This is a direct MySQL/MariaDB port of ptad_schema.sql (Postgres v1.4).
-- Conversion notes (so future readers understand every change made):
--   • PostgreSQL ENUM TYPEs        -> MySQL inline ENUM(...) on each column
--     (MySQL has no standalone reusable enum type; each column repeats its
--      own ENUM list — a small duplication traded for MySQL compatibility).
--   • SMALLSERIAL / SERIAL / BIGSERIAL -> SMALLINT/INT/BIGINT AUTO_INCREMENT
--   • TIMESTAMPTZ                  -> TIMESTAMP (MySQL has no separate tz-aware
--                                     type; app/server timezone is fixed to
--                                     Asia/Karachi per config.php, so this is safe)
--   • now()                        -> CURRENT_TIMESTAMP
--   • JSONB                        -> JSON (MySQL's native JSON type)
--   • TEXT ... UNIQUE               -> VARCHAR(n) UNIQUE where a unique/indexed
--                                     TEXT column existed, because MySQL cannot
--                                     put a UNIQUE index on an unbounded TEXT
--                                     column without an explicit key length.
--   • text_pattern_ops indexes      -> plain MySQL indexes (InnoDB's default
--                                     collation already supports prefix/LIKE
--                                     "starts with" searches efficiently)
--   • GIN full-text index on tsvector -> MySQL native FULLTEXT index
--   • DROP SCHEMA / CREATE SCHEMA / search_path -> not used; MySQL has no
--     schema-within-database concept the same way. We operate directly on
--     the `ptad` database (already created via phpMyAdmin).
--   • CASCADE on DROP               -> MySQL InnoDB handles FK cascade via
--                                     ON DELETE CASCADE on each FK, same as
--                                     the original design already specified.
--
-- Nothing about the DESIGN changed: same 10 core tables + coverage_rules,
-- same behaviour-as-data philosophy, same special-case handling (SAFTA
-- negative list, Russia coverage_rules, Uzbekistan concession mechanics,
-- D-8 member_status, v1.4 link-control fields). This file is functionally
-- equivalent to the frozen Postgres schema, just written in MySQL's dialect.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Clean rebuild during development (safe: drops then recreates everything).
DROP TABLE IF EXISTS v_tariff_search;
DROP TABLE IF EXISTS v_agreement_membership;
DROP VIEW IF EXISTS v_tariff_search;
DROP VIEW IF EXISTS v_agreement_membership;
DROP TABLE IF EXISTS coverage_rules;
DROP TABLE IF EXISTS hs_transpositions;
DROP TABLE IF EXISTS verification_links;
DROP TABLE IF EXISTS content_sections;
DROP TABLE IF EXISTS staging_categories;
DROP TABLE IF EXISTS tariff_rates;
DROP TABLE IF EXISTS tariff_lines;
DROP TABLE IF EXISTS agreement_members;
DROP TABLE IF EXISTS agreements;
DROP TABLE IF EXISTS section_types;
DROP TABLE IF EXISTS countries;

SET FOREIGN_KEY_CHECKS = 1;


-- ============================================================================
-- SECTION 2 — REFERENCE TABLES (shared look-ups used across all modules)
-- ============================================================================

-- 2.1 COUNTRIES — one row per country/territory referenced anywhere in PTAD.
CREATE TABLE countries (
    id              SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    iso2            CHAR(2) UNIQUE,
    iso3            CHAR(3) UNIQUE,
    name            VARCHAR(255) NOT NULL UNIQUE,
    is_ldc          BOOLEAN NOT NULL DEFAULT FALSE,
    notes           TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.2 SECTION TYPES — the catalogue of functional sections (worksheet kinds).
CREATE TABLE section_types (
    id              SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(100) NOT NULL UNIQUE,
    display_name    VARCHAR(255) NOT NULL,
    description     TEXT,
    typical_order   SMALLINT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- SECTION 3 — AGREEMENTS (one row per arrangement) + MEMBERS
-- ============================================================================

-- 3.1 AGREEMENTS — the master row for each of the 26 (and future) modules.
CREATE TABLE agreements (
    id                  SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code                VARCHAR(50) NOT NULL UNIQUE,
    short_name          VARCHAR(255) NOT NULL,
    full_name           TEXT NOT NULL,
    type                ENUM('FTA','PTA','RTA','MTA','GSP') NOT NULL,
    source_workbook     VARCHAR(255),

    -- Behaviour flags (these replace fragile special-case code) ----------
    list_type           ENUM('positive','negative') NOT NULL DEFAULT 'positive',
    coverage            ENUM('full','partial','links_only') NOT NULL DEFAULT 'full',
    staging             ENUM('none','calendar_year','anniversary') NOT NULL DEFAULT 'none',
    anniversary_month   SMALLINT,
    anniversary_day     SMALLINT,
    entry_into_force    DATE,
    staging_horizon_yrs SMALLINT,

    default_ceiling_pct DECIMAL(6,3),
    ceiling_is_upper    BOOLEAN DEFAULT TRUE,

    status              VARCHAR(50) DEFAULT 'in_force',

    -- Link-control register (mirrors the Navigator's hidden 'Link_Admin' sheet)
    live_file_name      VARCHAR(255),
    onedrive_url        TEXT,
    tdap_web_url        TEXT,
    active_url          TEXT,
    active_source       ENUM('OneDrive','TDAP','Portal') DEFAULT 'OneDrive',
    is_placeholder      BOOLEAN NOT NULL DEFAULT FALSE,
    admin_notes         TEXT,
    summary             TEXT,
    last_reviewed       DATE,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.2 AGREEMENT_MEMBERS — which countries belong to each agreement.
CREATE TABLE agreement_members (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agreement_id        SMALLINT UNSIGNED NOT NULL,
    country_id          SMALLINT UNSIGNED NOT NULL,
    is_ldc_in_agreement BOOLEAN,
    member_ceiling_pct  DECIMAL(6,3),
    role                VARCHAR(50),
    status              ENUM('implemented','not_implemented','pending','suspended')
                            NOT NULL DEFAULT 'implemented',
    status_note         TEXT,
    notes               TEXT,
    UNIQUE KEY uq_agreement_country (agreement_id, country_id),
    CONSTRAINT fk_members_agreement FOREIGN KEY (agreement_id)
        REFERENCES agreements(id) ON DELETE CASCADE,
    CONSTRAINT fk_members_country FOREIGN KEY (country_id)
        REFERENCES countries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- SECTION 4 — TARIFF LINES (the heart of the system)
-- ============================================================================
CREATE TABLE tariff_lines (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agreement_id        SMALLINT UNSIGNED NOT NULL,

    -- Direction & market -------------------------------------------------
    import_country_id   SMALLINT UNSIGNED,
    member_country_id   SMALLINT UNSIGNED,

    -- HS / product code ----------------------------------------------------
    hs_code_raw         VARCHAR(50) NOT NULL,
    hs_code_norm        VARCHAR(50) NOT NULL,
    hs_digits           SMALLINT,
    hs6                 CHAR(6),
    product_desc        TEXT,

    -- Base / MFN reference figure (stored WITH its meaning) --------------
    mfn_kind            ENUM('ad_valorem','specific','compound','mixed','free',
                              'excluded','text_only','unspecified')
                            DEFAULT 'unspecified',
    mfn_value           DECIMAL(12,4),
    mfn_text            TEXT,
    mfn_meaning         ENUM('base_at_negotiation','current_verified','unreconciled','not_available')
                            DEFAULT 'base_at_negotiation',
    mfn_as_of           VARCHAR(255),

    -- For SAFTA negative-list rows: TRUE means this line is the EXCLUDED one.
    is_excluded         BOOLEAN NOT NULL DEFAULT FALSE,

    -- Eligibility as stated on the workbook, plus system-checked flag -----
    stated_eligibility  VARCHAR(255),
    eligibility_checked BOOLEAN DEFAULT FALSE,
    eligibility_matches BOOLEAN,

    staging_category    VARCHAR(50),

    remarks             TEXT,
    source_reference    TEXT,
    source_row          VARCHAR(255),
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_lines_agreement FOREIGN KEY (agreement_id)
        REFERENCES agreements(id) ON DELETE CASCADE,
    CONSTRAINT fk_lines_import_country FOREIGN KEY (import_country_id)
        REFERENCES countries(id),
    CONSTRAINT fk_lines_member_country FOREIGN KEY (member_country_id)
        REFERENCES countries(id),

    FULLTEXT KEY ft_lines_desc (product_desc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- SECTION 5 — TARIFF RATES (conditional & time-varying preferential rates)
-- ============================================================================
CREATE TABLE tariff_rates (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tariff_line_id      BIGINT UNSIGNED NOT NULL,

    `condition`         ENUM('standard','ldc','non_ldc','within_quota','above_quota',
                              'seasonal','origin_based')
                            NOT NULL DEFAULT 'standard',
    component           ENUM('total','cd','rd','acd') NOT NULL DEFAULT 'total',
    applies_year        SMALLINT,
    applies_from        DATE,
    applies_to          DATE,

    -- The rate itself, stored three ways ----------------------------------
    rate_kind           ENUM('ad_valorem','specific','compound','mixed','free',
                              'excluded','text_only','unspecified')
                            NOT NULL DEFAULT 'ad_valorem',
    rate_value          DECIMAL(12,4),
    rate_text           TEXT,
    effective_advalorem DECIMAL(12,4),

    -- Quota / seasonal qualifiers -------------------------------------------
    quota_note          TEXT,
    season_note         TEXT,

    -- Concession MECHANICS (v1.2) -------------------------------------------
    base_value          DECIMAL(12,4),
    base_text           TEXT,
    concession_type     ENUM('reduction_pct','full_exemption','fixed_new_rate','no_concession'),
    concession_pct      DECIMAL(6,3),
    specific_floor_text TEXT,
    outcome_value       DECIMAL(12,4),
    outcome_text        TEXT,

    -- Tariff advantage as recorded in the workbook ---------------------------
    advantage_value     DECIMAL(12,4),
    advantage_text      TEXT,

    notes               TEXT,

    CONSTRAINT fk_rates_line FOREIGN KEY (tariff_line_id)
        REFERENCES tariff_lines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- SECTION 6 — STAGING SCHEDULE CATEGORIES (the phase-down "recipes")
-- ============================================================================
CREATE TABLE staging_categories (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agreement_id    SMALLINT UNSIGNED NOT NULL,
    code            VARCHAR(50) NOT NULL,
    description     TEXT,
    UNIQUE KEY uq_agreement_code (agreement_id, code),
    CONSTRAINT fk_staging_agreement FOREIGN KEY (agreement_id)
        REFERENCES agreements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- SECTION 7 — NON-TARIFF CONTENT SECTIONS (Rules of Origin, Docs, etc.)
-- ============================================================================
CREATE TABLE content_sections (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agreement_id    SMALLINT UNSIGNED NOT NULL,
    section_type_id SMALLINT UNSIGNED NOT NULL,
    row_order       INT,
    title           VARCHAR(500),
    body            TEXT,
    fields          JSON,
    hs_scope        CHAR(6),
    source_ref      TEXT,
    verification_url TEXT,

    CONSTRAINT fk_content_agreement FOREIGN KEY (agreement_id)
        REFERENCES agreements(id) ON DELETE CASCADE,
    CONSTRAINT fk_content_section_type FOREIGN KEY (section_type_id)
        REFERENCES section_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- SECTION 8 — VERIFICATION LINKS & SOURCES (the hybrid-approach backbone)
-- ============================================================================
CREATE TABLE verification_links (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agreement_id    SMALLINT UNSIGNED NOT NULL,
    category        VARCHAR(255),
    resource_name   VARCHAR(255),
    purpose         TEXT,
    url             TEXT NOT NULL,
    official_source VARCHAR(255),
    hs_scope        CHAR(6),
    last_updated_note VARCHAR(255),

    CONSTRAINT fk_links_agreement FOREIGN KEY (agreement_id)
        REFERENCES agreements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- SECTION 9 — HS TRANSPOSITION (e.g. Türkiye 12-digit <-> Pakistan 8-digit)
-- ============================================================================
CREATE TABLE hs_transpositions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agreement_id    SMALLINT UNSIGNED,
    from_system     VARCHAR(50),
    from_code_raw   VARCHAR(50) NOT NULL,
    from_code_norm  VARCHAR(50) NOT NULL,
    to_system       VARCHAR(50),
    to_code_raw     VARCHAR(50) NOT NULL,
    to_code_norm    VARCHAR(50) NOT NULL,
    authority       VARCHAR(255),
    notes           TEXT,

    CONSTRAINT fk_trans_agreement FOREIGN KEY (agreement_id)
        REFERENCES agreements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- SECTION 9B — COVERAGE RULES (chapter/heading-level eligibility with exceptions)
-- ============================================================================
CREATE TABLE coverage_rules (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agreement_id    SMALLINT UNSIGNED NOT NULL,
    list_no         INT,
    hs_prefix       VARCHAR(50) NOT NULL,
    hs_prefix_len   SMALLINT,
    rule_effect     ENUM('include','exclude') NOT NULL DEFAULT 'include',
    raw_coverage    TEXT,
    description     TEXT,
    beneficiary_scope TEXT,
    user_note       TEXT,
    source_ref      TEXT,
    notes           TEXT,

    CONSTRAINT fk_coverage_agreement FOREIGN KEY (agreement_id)
        REFERENCES agreements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_coverage_agreement ON coverage_rules (agreement_id);
CREATE INDEX idx_coverage_prefix    ON coverage_rules (hs_prefix);


-- ============================================================================
-- SECTION 10 — INDEXES (make searches fast — "international quality" speed)
-- ============================================================================
CREATE INDEX idx_lines_hs_norm    ON tariff_lines (hs_code_norm);
CREATE INDEX idx_lines_hs6        ON tariff_lines (hs6);
CREATE INDEX idx_lines_agreement  ON tariff_lines (agreement_id);
CREATE INDEX idx_lines_member     ON tariff_lines (member_country_id);
-- MySQL's default InnoDB index on hs_code_norm already supports fast
-- "starts-with" (prefix) lookups, so no separate text_pattern_ops-style
-- index is needed the way Postgres required one.

CREATE INDEX idx_rates_line       ON tariff_rates (tariff_line_id);
CREATE INDEX idx_rates_year       ON tariff_rates (applies_year);
CREATE INDEX idx_rates_condition  ON tariff_rates (`condition`);

CREATE INDEX idx_content_agreement ON content_sections (agreement_id);
CREATE INDEX idx_content_type      ON content_sections (section_type_id);
CREATE INDEX idx_links_agreement   ON verification_links (agreement_id);
CREATE INDEX idx_trans_from        ON hs_transpositions (from_code_norm);
CREATE INDEX idx_trans_to          ON hs_transpositions (to_code_norm);

-- Full-text search on product descriptions was already created inline above
-- (ft_lines_desc on tariff_lines.product_desc) since MySQL requires FULLTEXT
-- indexes to be declared as part of, or immediately after, table creation.


-- ============================================================================
-- SECTION 11 — A READY-MADE SEARCH VIEW (the API's main entry point)
-- ============================================================================
CREATE OR REPLACE VIEW v_tariff_search AS
SELECT
    tl.id                AS line_id,
    a.code               AS agreement_code,
    a.short_name         AS agreement_name,
    a.type               AS agreement_type,
    a.list_type,
    a.coverage,
    ic.name              AS import_country,
    mc.name              AS member_country,
    tl.hs_code_raw,
    tl.hs_code_norm,
    tl.hs6,
    tl.product_desc,
    tl.mfn_kind,
    tl.mfn_value,
    tl.mfn_text,
    tl.mfn_meaning,
    tl.is_excluded,
    tl.staging_category,
    tr.`condition`,
    tr.component,
    tr.applies_year,
    tr.rate_kind,
    tr.rate_value,
    tr.rate_text,
    tr.effective_advalorem,
    tr.quota_note,
    tr.season_note,
    tr.advantage_value,
    tl.remarks,
    tl.source_reference
FROM tariff_lines tl
JOIN agreements a        ON a.id  = tl.agreement_id
LEFT JOIN countries ic   ON ic.id = tl.import_country_id
LEFT JOIN countries mc   ON mc.id = tl.member_country_id
LEFT JOIN tariff_rates tr ON tr.tariff_line_id = tl.id;


-- ============================================================================
-- SECTION 12 — MEMBERSHIP VIEW (powers the Country Navigator honestly)
-- ============================================================================
CREATE OR REPLACE VIEW v_agreement_membership AS
SELECT
    a.code            AS agreement_code,
    a.short_name      AS agreement_name,
    a.type            AS agreement_type,
    c.name            AS member_country,
    c.iso3            AS member_iso3,
    am.role,
    am.status         AS member_status,
    am.status_note,
    am.is_ldc_in_agreement,
    am.member_ceiling_pct
FROM agreement_members am
JOIN agreements a ON a.id = am.agreement_id
JOIN countries  c ON c.id = am.country_id;

-- ============================================================================
-- END OF MYSQL SCHEMA (converted from Postgres v1.4 FINAL)
-- ============================================================================