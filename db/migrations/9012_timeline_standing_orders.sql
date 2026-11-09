-- Obligations timeline ("Harmonogram"): regular payments by standing order and per-supplier settings — fork-private
-- migration, 9xxx range.
--
-- The application cannot see the owner's bank account, so a payment made by a standing order would show up as
-- overdue on the timeline. The owner records the standing order here; the timeline then treats every item it covers
-- (flat-tax advance, insurance advance, income tax advance) as paid on the payment day of the order.
--
--   timeline_standing_orders  one row per standing order: what it pays (`type`), the amount, the day of the month
--                             it is executed on and the window in which it is valid. A day beyond the end of a
--                             month (31 in February) means the last day of that month.
--   timeline_settings         timeline preferences of the supplier. `invoicing_due` decides when "issue the invoices
--                             for the month" is due: on the last day of that month (the owner issues invoices on the
--                             last day, DUZP = last day) or on the Nth working day of the following month.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS only. Re-run safe.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS timeline_standing_orders (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id  INT UNSIGNED NOT NULL,
    type         ENUM('pausal','insurance_social','insurance_health','income_tax_advance') NOT NULL,
    amount       DECIMAL(12,2) NOT NULL,
    day_of_month TINYINT UNSIGNED NOT NULL COMMENT '1-31; clamped to the length of the month',
    valid_from   DATE NOT NULL,
    valid_to     DATE NULL COMMENT 'NULL = until cancelled',
    note         VARCHAR(255) NULL,
    created_by   BIGINT UNSIGNED NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_timeline_so_supplier (supplier_id, type),
    CONSTRAINT fk_timeline_so_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_timeline_so_user     FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS timeline_settings (
    supplier_id           INT UNSIGNED NOT NULL PRIMARY KEY,
    invoicing_due         ENUM('month_end','working_day_n') NOT NULL DEFAULT 'month_end',
    invoicing_working_day TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'N of working_day_n: 1-20',
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_timeline_settings_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
