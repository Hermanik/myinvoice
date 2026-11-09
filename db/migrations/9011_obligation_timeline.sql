-- Obligations timeline ("Harmonogram") — fork-private migration, 9xxx range.
--
-- The timeline itself is computed on the fly from the supplier's settings and data (VAT status history,
-- tax profiles, invoices, the submission archive). Only what cannot be derived is stored:
--
--   timeline_marks         manual "done" marks (+ optional note) keyed by supplier and the stable key of a computed
--                          item (e.g. 'dph:2026-11', 'ins_social:2027-02'). Lets the owner tick off items without
--                          automatic evidence (insurance advances, a return filed outside the application).
--   timeline_custom_items  user-defined items ("Oznámit klientům DPH a nový účet"): title, due date, optional window
--                          start, category, note, link, done flag.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS only. Re-run safe.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS timeline_marks (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    item_key    VARCHAR(120) NOT NULL COMMENT 'Stable key of the computed item, see ObligationTimelineService',
    is_done     TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = a note only, the item is still open',
    note        VARCHAR(500) NULL,
    marked_by   BIGINT UNSIGNED NULL,
    marked_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_timeline_marks_item (supplier_id, item_key),
    CONSTRAINT fk_timeline_marks_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_timeline_marks_user     FOREIGN KEY (marked_by)   REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS timeline_custom_items (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    title       VARCHAR(255) NOT NULL,
    due_date    DATE NOT NULL,
    start_date  DATE NULL COMMENT 'Optional start of the window in which the item should be done',
    category    ENUM('dph','income_tax','insurance','pausal','registration','invoicing','custom') NOT NULL DEFAULT 'custom',
    note        VARCHAR(1000) NULL,
    url         VARCHAR(500) NULL COMMENT 'Internal path ("/clients") or an http(s) URL',
    done_at     DATETIME NULL,
    done_by     BIGINT UNSIGNED NULL,
    created_by  BIGINT UNSIGNED NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_timeline_custom_due (supplier_id, due_date),
    CONSTRAINT fk_timeline_custom_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_timeline_custom_done_by  FOREIGN KEY (done_by)     REFERENCES users(id)    ON DELETE SET NULL,
    CONSTRAINT fk_timeline_custom_created  FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
