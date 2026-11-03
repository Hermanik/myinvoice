-- Statutory late-payment interest (uroky z prodleni) — fork-private migration, 9xxx range.
--
-- Legal basis (see api/src/Service/LateInterest/LateInterestCalculator.php for the full notes):
--   * Civil Code § 1970 + § 1972, https://www.zakonyprolidi.cz/cs/2012-89
--   * Government regulation No. 351/2013 Sb. § 2 (rate = CNB 2T repo rate valid on the first day of
--     the calendar half-year in which the delay began + 8 percentage points) and § 3 (minimum
--     fixed cost of claiming a receivable between entrepreneurs, CZK 1,200),
--     https://www.zakonyprolidi.cz/cs/2013-351
--
-- What this adds
--   * cnb_repo_rates                  global, append-only codebook of the CNB 2T repo rate
--                                     (a row = "from this date the rate is X"), seeded from the
--                                     official history
--                                     https://www.cnb.cz/cs/casto-kladene-dotazy/.galleries/vyvoj_repo_historie.txt
--   * supplier.late_interest_*        global opt-in (OFF by default) + reminder / fixed-cost options
--   * clients.late_interest           per-client override  (NULL = inherit, 1 = on, 0 = off)
--   * invoices.late_interest          per-invoice override (NULL = inherit, 1 = on, 0 = off)
--   * invoice_late_interest_claims    links an interest invoice (draft) to the invoice it bills and
--                                     remembers the period it covers, so a second run never bills the
--                                     same days or the fixed cost twice
--   * vat_classifications 'MPD'       sale classification "outside the scope of VAT"; it carries no
--                                     DPHDP3 line and no KH section, so VAT books / returns skip it
--                                     and the "unclassified 0 % line" warning does not fire
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS, INSERT IGNORE / guarded INSERT.
-- Re-running never touches an existing rate row (the codebook is append-only).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cnb_repo_rates (
    valid_from   DATE NOT NULL COMMENT 'First day the rate applies; it holds until the next row',
    rate_percent DECIMAL(5,2) NOT NULL COMMENT 'CNB 2T repo rate, % p.a.',
    source       ENUM('seed','cnb','manual') NOT NULL DEFAULT 'manual'
                   COMMENT 'seed = shipped with the migration, cnb = fetched from the official CNB file, manual = typed in by an admin',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Official history (CNB), from the rate that was in force on 2014-01-01 (the day NV 351/2013 took
-- effect) up to the decision of 2026-06-18 (effective 2026-06-19). Verified 2026-11-03.
INSERT IGNORE INTO cnb_repo_rates (valid_from, rate_percent, source) VALUES
    ('2012-11-02', 0.05, 'seed'),
    ('2017-08-04', 0.25, 'seed'),
    ('2017-11-03', 0.50, 'seed'),
    ('2018-02-02', 0.75, 'seed'),
    ('2018-06-28', 1.00, 'seed'),
    ('2018-08-03', 1.25, 'seed'),
    ('2018-09-27', 1.50, 'seed'),
    ('2018-11-02', 1.75, 'seed'),
    ('2019-05-03', 2.00, 'seed'),
    ('2020-02-07', 2.25, 'seed'),
    ('2020-03-17', 1.75, 'seed'),
    ('2020-03-27', 1.00, 'seed'),
    ('2020-05-11', 0.25, 'seed'),
    ('2021-06-24', 0.50, 'seed'),
    ('2021-08-06', 0.75, 'seed'),
    ('2021-10-01', 1.50, 'seed'),
    ('2021-11-05', 2.75, 'seed'),
    ('2021-12-23', 3.75, 'seed'),
    ('2022-02-04', 4.50, 'seed'),
    ('2022-04-01', 5.00, 'seed'),
    ('2022-05-06', 5.75, 'seed'),
    ('2022-06-23', 7.00, 'seed'),
    ('2023-12-22', 6.75, 'seed'),
    ('2024-02-09', 6.25, 'seed'),
    ('2024-03-21', 5.75, 'seed'),
    ('2024-05-03', 5.25, 'seed'),
    ('2024-06-28', 4.75, 'seed'),
    ('2024-08-02', 4.50, 'seed'),
    ('2024-09-26', 4.25, 'seed'),
    ('2024-11-08', 4.00, 'seed'),
    ('2025-02-07', 3.75, 'seed'),
    ('2025-05-09', 3.50, 'seed'),
    ('2026-06-19', 3.75, 'seed');

-- "The codebook is complete up to this date": a rate for a delay that began in a half-year starting
-- AFTER this date cannot be trusted (the CNB may have changed the rate in between), so reminders
-- skip the interest block and billing asks for a refresh. Advanced by every fetch / manual entry.
INSERT IGNORE INTO app_meta (k, v) VALUES ('late_interest.repo_rates_verified_on', '2026-11-03');

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS late_interest_enabled TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Global opt-in for statutory late-payment interest (off by default)',
    ADD COLUMN IF NOT EXISTS late_interest_in_reminders TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Show the accrued interest in payment reminders (only effective when enabled)',
    ADD COLUMN IF NOT EXISTS late_interest_claim_fixed_cost TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Also claim the fixed minimum cost of claiming a receivable (NV 351/2013 § 3), business customers only';

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS late_interest TINYINT(1) NULL DEFAULT NULL
        COMMENT 'Per-client override of the supplier opt-in: NULL = inherit, 1 = on, 0 = off';

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS late_interest TINYINT(1) NULL DEFAULT NULL
        COMMENT 'Per-invoice override of the client / supplier setting: NULL = inherit, 1 = on, 0 = off';

CREATE TABLE IF NOT EXISTS invoice_late_interest_claims (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id       INT UNSIGNED NOT NULL,
    source_invoice_id BIGINT UNSIGNED NOT NULL COMMENT 'The overdue invoice the interest is billed for',
    invoice_id        BIGINT UNSIGNED NOT NULL COMMENT 'The interest invoice (created as a draft)',
    period_from       DATE NOT NULL,
    period_to         DATE NOT NULL,
    rate_percent      DECIMAL(5,2) NOT NULL COMMENT 'Annual rate used (repo + 8 p.p.)',
    interest_amount   DECIMAL(12,2) NOT NULL COMMENT 'In the currency of the source invoice',
    fixed_cost_amount DECIMAL(12,2) NULL COMMENT 'Fixed cost line in the invoice currency; NULL = not claimed by this invoice',
    created_by        BIGINT UNSIGNED NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_lic_invoice (invoice_id),
    KEY idx_lic_source (source_invoice_id),
    CONSTRAINT fk_lic_supplier FOREIGN KEY (supplier_id)       REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_lic_source   FOREIGN KEY (source_invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_lic_invoice  FOREIGN KEY (invoice_id)        REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_lic_user     FOREIGN KEY (created_by)        REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sale classification "outside the scope of VAT" (interest and compensation are not consideration
-- for a supply, § 2 ZDPH). Global row, no DPHDP3 line / KH section on purpose.
INSERT INTO vat_classifications
    (supplier_id, code, label, direction, dphdp3_line, dphdp3_line_secondary, kh_section, vat_rate, is_reverse_charge, display_order, archived)
SELECT NULL, 'MPD', 'Mimo předmět daně (§ 2 ZDPH) – např. úrok z prodlení, náhrada nákladů', 'sale', NULL, NULL, NULL, 0.00, 0, 90, 0
  WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = 'MPD');
