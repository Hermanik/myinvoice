-- Filing lifecycle + VAT payment tracking for archived tax submissions
-- (fork-private migration, 9xxx range).
--
-- tax_submissions used to be a plain archive of generated XML. Each row now also carries
--   * the filing form (B/O/N/E/R) so that "one filed submission per type + period + form"
--     can be enforced,
--   * a state: generated -> filed -> confirmed (filing channel, reference, receipt),
--   * for DPH returns the payment of the own tax liability (manual or recognised from a bank
--     transaction).
--
-- The receipt (EPO .p7s / PDF / ZFO) is stored in the row itself with size + sha256, next to
-- xml_content / xml_size_bytes / xml_sha256, so it is covered by the database backup.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS / ADD ... IF NOT EXISTS; the back-fill UPDATEs only
-- touch rows that still carry the column default.

SET NAMES utf8mb4;

ALTER TABLE tax_submissions
  ADD COLUMN IF NOT EXISTS submission_form VARCHAR(2) NOT NULL DEFAULT 'B'
      COMMENT 'Filing form: B/O (DPH), B/O/N/E (KH), R/N (SH), B for the rest' AFTER period_quarter,
  ADD COLUMN IF NOT EXISTS filing_status ENUM('generated','filed','confirmed') NOT NULL DEFAULT 'generated',
  ADD COLUMN IF NOT EXISTS filed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS filing_channel ENUM('epo','data_box','other') NULL,
  ADD COLUMN IF NOT EXISTS filing_reference VARCHAR(100) NULL COMMENT 'Cislo podani / reference number',
  ADD COLUMN IF NOT EXISTS filed_by BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS confirmed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS receipt_content LONGBLOB NULL,
  ADD COLUMN IF NOT EXISTS receipt_name VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS receipt_mime VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS receipt_size_bytes INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS receipt_sha256 CHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS paid_at DATE NULL COMMENT 'DPH liability paid on this date',
  ADD COLUMN IF NOT EXISTS paid_amount DECIMAL(14,2) NULL,
  ADD COLUMN IF NOT EXISTS paid_source ENUM('manual','bank') NULL,
  ADD COLUMN IF NOT EXISTS paid_bank_transaction_id BIGINT UNSIGNED NULL;

-- Back-fill the form of rows archived before this migration (DPH + KH keep it in their summary).
UPDATE tax_submissions
   SET submission_form = 'R'
 WHERE form_code = 'dphshv' AND submission_form = 'B';

UPDATE tax_submissions
   SET submission_form = UPPER(JSON_UNQUOTE(JSON_EXTRACT(summary_json, '$.form')))
 WHERE form_code IN ('dphdp3', 'dphkh1')
   AND submission_form = 'B'
   AND summary_json IS NOT NULL
   AND JSON_VALID(summary_json) = 1
   AND JSON_UNQUOTE(JSON_EXTRACT(summary_json, '$.form')) REGEXP '^[A-Za-z]$';

-- "Filed slot": non-NULL only while a submission is filed/confirmed. NULLs never collide in a
-- unique index, so this is a DB-level guard (on top of the service check) that one
-- (supplier, type, period, form) can be filed/confirmed at most once.
ALTER TABLE tax_submissions
  ADD COLUMN IF NOT EXISTS filed_slot VARCHAR(64)
      GENERATED ALWAYS AS (
        IF(filing_status IN ('filed','confirmed'),
           CONCAT_WS('|', supplier_id, form_code, period_year,
                     COALESCE(period_month, 0), COALESCE(period_quarter, 0), submission_form),
           NULL)
      ) VIRTUAL;

ALTER TABLE tax_submissions
  ADD UNIQUE INDEX IF NOT EXISTS uq_tax_submissions_filed_slot (filed_slot),
  ADD INDEX IF NOT EXISTS idx_tax_submissions_filing (supplier_id, filing_status, form_code),
  ADD INDEX IF NOT EXISTS idx_tax_submissions_paid_tx (paid_bank_transaction_id);

ALTER TABLE tax_submissions
  ADD CONSTRAINT fk_tax_submissions_paid_tx
    FOREIGN KEY IF NOT EXISTS (paid_bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE SET NULL;
