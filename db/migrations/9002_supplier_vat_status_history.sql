-- Dated history of the supplier's VAT status (fork-private migration, 9xxx range).
--
-- Until now the VAT status was a single global flag (supplier.is_vat_payer +
-- supplier.is_identified). That cannot express "VAT payer from 2027-01-01" while an
-- invoice for December 2026 (DUZP 2026-12-31) issued in January 2027 must still be a
-- non-payer invoice. Everything that depends on the status now decides by a DATE
-- (for an invoice: its DUZP, falling back to the issue date).
--
-- Status at date D = the row with the greatest valid_from <= D. Rows may lie in the
-- future. A row's date is never edited (delete + re-create instead), so the unique
-- key (supplier_id, valid_from) is also the lookup index.
--
-- supplier.is_vat_payer / supplier.is_identified stay as a CACHE of "status today"
-- for the many readers that only care about the present (recomputed on every history
-- change and daily by cron-refresh-vat-status).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS + guarded seed insert. Re-run safe.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_vat_status_history (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    valid_from  DATE NOT NULL COMMENT 'First day the status applies; the status holds until the next row',
    status      ENUM('payer','non_payer','identified') NOT NULL
                  COMMENT 'payer = platce DPH, non_payer = neplatce, identified = identifikovana osoba (non-payer domestically)',
    note        VARCHAR(255) NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by  BIGINT UNSIGNED NULL,

    UNIQUE KEY uq_svsh_supplier_from (supplier_id, valid_from),
    CONSTRAINT fk_svsh_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_svsh_user     FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed exactly one initial row per existing supplier from the current flags, only
-- for suppliers that have no history yet (so a re-run never duplicates or overrides).
INSERT INTO supplier_vat_status_history (supplier_id, valid_from, status, note)
SELECT s.id,
       '2000-01-01',
       CASE WHEN s.is_vat_payer = 1 THEN 'payer'
            WHEN s.is_identified = 1 THEN 'identified'
            ELSE 'non_payer' END,
       'Initial status (migrated from supplier flags)'
  FROM supplier s
 WHERE NOT EXISTS (SELECT 1 FROM supplier_vat_status_history h WHERE h.supplier_id = s.id);
