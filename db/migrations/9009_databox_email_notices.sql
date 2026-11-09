-- Czech data boxes watched through the official ISDS notification e-mails — fork-private migration, 9xxx range.
--
-- Some box holders cannot add a separate limited user for the application (ISDS refuses to add the holder as an
-- entrusted user of his own box), and the holder's own login would legally DELIVER every message on each list call.
-- The notification e-mails ISDS sends for each new message deliver nothing, so the application can watch a box
-- by reading them over IMAP instead.
--
-- databoxes.connection_mode        'isds' = logins + synchronisation (default), 'email' = e-mail notices only, no login
-- databox_messages.source          where the row came from: 'isds' (envelope list) or 'email' (notification e-mail)
-- databox_email_settings           one row per supplier: which IMAP account + folder to read, alert address, last scan
-- databox_email_notices            every processed notification e-mail (dedup + diagnostics). Never stores the body.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS and CREATE TABLE IF NOT EXISTS only. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE databoxes
  ADD COLUMN IF NOT EXISTS connection_mode ENUM('isds','email') NOT NULL DEFAULT 'isds'
    COMMENT 'email = watched through ISDS notification e-mails only (no login, no synchronisation, no ISDS action)' AFTER environment;

ALTER TABLE databox_messages
  ADD COLUMN IF NOT EXISTS source ENUM('isds','email') NOT NULL DEFAULT 'isds'
    COMMENT 'email = created from a notification e-mail, replaced by isds when a synchronisation later sees the envelope' AFTER direction;

CREATE TABLE IF NOT EXISTS databox_email_settings (
    supplier_id     INT UNSIGNED NOT NULL PRIMARY KEY,
    imap_account_id BIGINT UNSIGNED NULL COMMENT 'Reuses an IMAP account of bank_email_imap_settings (credentials stay there)',
    folder          VARCHAR(190) NOT NULL DEFAULT 'INBOX',
    enabled         TINYINT(1) NOT NULL DEFAULT 0,
    alert_email     VARCHAR(255) NULL COMMENT 'Where to send one e-mail per newly found message, NULL = no alerts',
    last_scan_at    DATETIME NULL,
    last_scan_status ENUM('ok','error') NULL,
    last_scan_message VARCHAR(500) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_databox_email_settings_enabled (enabled),
    CONSTRAINT fk_databox_email_settings_supplier FOREIGN KEY (supplier_id)     REFERENCES supplier(id)                 ON DELETE CASCADE,
    CONSTRAINT fk_databox_email_settings_imap     FOREIGN KEY (imap_account_id) REFERENCES bank_email_imap_settings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS databox_email_notices (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id        INT UNSIGNED NOT NULL,
    imap_account_id    BIGINT UNSIGNED NULL,
    dedup_key          CHAR(64) NOT NULL COMMENT 'SHA-256 of the e-mail Message-ID, or of the content hash when the e-mail has none',
    message_id         VARCHAR(255) NULL,
    received_at        DATETIME NULL COMMENT 'Date header of the notification e-mail',
    status             ENUM('linked','ignored','rejected','unknown_box','parse_failed') NOT NULL,
    detail             VARCHAR(255) NULL COMMENT 'Stable code or short reason, never e-mail content',
    dm_id              VARCHAR(20) NULL,
    databox_message_id BIGINT UNSIGNED NULL,
    processed_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_databox_email_notice (supplier_id, dedup_key),
    KEY idx_databox_email_notices_status (supplier_id, status, processed_at),
    KEY idx_databox_email_notices_message (databox_message_id),
    CONSTRAINT fk_databox_email_notices_supplier FOREIGN KEY (supplier_id)        REFERENCES supplier(id)                 ON DELETE CASCADE,
    CONSTRAINT fk_databox_email_notices_imap     FOREIGN KEY (imap_account_id)    REFERENCES bank_email_imap_settings(id) ON DELETE SET NULL,
    CONSTRAINT fk_databox_email_notices_message  FOREIGN KEY (databox_message_id) REFERENCES databox_messages(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
