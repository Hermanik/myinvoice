-- Registration deduction agenda + sequential supplementary returns (fork-private migration, 9xxx range).
--
-- 1) vat_registration_deductions: the items of the VAT deduction a new payer may claim for assets acquired
--    before the registration (§ 79 ZDPH) and, on deregistration, the opposite reduction (§ 79a ZDPH).
--    The total flows into row 45 of the DPHDP3 of the period in which the registration happened
--    (respectively the last period of the registration). `event_date` is the `valid_from` of the
--    supplier_vat_status_history row the item belongs to (registration row / first non-payer row);
--    history rows are never edited, so the date is a stable key.
--
-- 2) tax_submissions.filed_slot: migration 9004 allows ONE filed/confirmed submission per
--    (supplier, type, period, form). Some forms are legal to file repeatedly for the same period, in sequence:
--      * DPHDP3 form D  - dodatecne priznani (§ 141 DR), each one reports the difference against the last known tax;
--      * DPHKH1 forms N / E - nasledne KH (§ 101f ZDPH) and the quick answer to a call (stored as form V).
--    Their slot is NULL (NULLs never collide in a unique index); the sequence is kept by `filed_at` and the
--    filing service guards that a D is only filed on top of the return it was computed against.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS; MODIFY COLUMN with the same expression is a no-op on re-run.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS vat_registration_deductions (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id         INT UNSIGNED NOT NULL,
    kind                ENUM('registration','deregistration') NOT NULL DEFAULT 'registration'
                          COMMENT 'registration = deduction on registration (§ 79), deregistration = reduction on deregistration (§ 79a)',
    event_date          DATE NOT NULL
                          COMMENT 'valid_from of the VAT status history row: the day the supplier became a payer / the first day it is not',
    description         VARCHAR(255) NOT NULL,
    asset_type          ENUM('inventory','long_term_asset','other') NOT NULL,
    acquired_on         DATE NOT NULL COMMENT 'Date of the taxable supply (DUZP) of the acquisition',
    tax_base            DECIMAL(14,2) NOT NULL DEFAULT 0,
    vat_amount          DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Input VAT the deduction is claimed from (full original VAT unless a partial deduction applies)',
    adjustment_years    TINYINT UNSIGNED NULL COMMENT 'Long-term asset only: length of the adjustment period, 5 or 10 years (§ 78 odst. 3)',
    in_business_assets  TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Still part of the business assets on event_date; 0 = no deduction / no reduction',
    deduction_amount    DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Computed amount (always >= 0); kind decides the sign on the return',
    purchase_invoice_id BIGINT UNSIGNED NULL,
    source_item_id      BIGINT UNSIGNED NULL COMMENT 'Reduction item: the registration item it reverses',
    note                VARCHAR(500) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by          BIGINT UNSIGNED NULL,

    INDEX idx_vrd_supplier_event (supplier_id, kind, event_date),
    CONSTRAINT fk_vrd_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_vrd_purchase FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_vrd_source   FOREIGN KEY (source_item_id) REFERENCES vat_registration_deductions(id) ON DELETE SET NULL,
    CONSTRAINT fk_vrd_user     FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tax_submissions
  MODIFY COLUMN filed_slot VARCHAR(64)
      GENERATED ALWAYS AS (
        IF(filing_status IN ('filed','confirmed')
             AND NOT (form_code = 'dphdp3' AND submission_form = 'D')
             AND NOT (form_code = 'dphkh1' AND submission_form IN ('N','E','V')),
           CONCAT_WS('|', supplier_id, form_code, period_year,
                     COALESCE(period_month, 0), COALESCE(period_quarter, 0), submission_form),
           NULL)
      ) VIRTUAL;
