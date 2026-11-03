-- Czech data boxes (ISDS / "datove schranky") — fork-private migration, 9xxx range.
--
-- databoxes                         one row per box the supplier connected (FO / PFO / PO ...)
-- databox_credentials               up to two logins per box: 'sync' (background, should be a
--                                   list-only ISDS user) and 'action' (explicit user actions:
--                                   opening a message, sending). Passwords are stored encrypted
--                                   (SecretEncryption), never returned by the API.
-- databox_messages                  envelopes of received / sent messages (no content)
-- databox_message_files             stored ZFO blobs (signed message, signed delivery receipt)
-- databox_outbox(+_files)           staging area of the two-step send: "prepare" stores the exact payload, the user confirms,
--                                   "send" consumes it exactly once (no automatic or repeated sending)
-- tax_submission_databox_messages   link "archived EPO XML -> message that carried it". A separate
--                                   table on purpose, so the tax_submissions table itself stays
--                                   untouched (another branch extends it).
--
-- Sources: info.mojedatovaschranka.cz moved to datovka.gov.cz in 2025/2026; the web service
-- specification (WS_manipulace_s_datovymi_zpravami.pdf 3.8.1) and the operating rules
-- (https://datovka.gov.cz/info/cs/80.html) are summarised in api/src/Service/Databox/.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS only. Re-run safe.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS databoxes (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id           INT UNSIGNED NOT NULL,
    label                 VARCHAR(100) NOT NULL,
    environment           ENUM('production','test') NOT NULL DEFAULT 'production',
    db_id                 CHAR(7) NOT NULL COMMENT 'ISDS box ID, resolved from the login (GetOwnerInfoFromLogin2)',
    db_type               VARCHAR(20) NOT NULL COMMENT 'FO, PFO, PFO_ADVOK, PO, OVM ... as reported by ISDS',
    owner_name            VARCHAR(255) NULL,
    is_active             TINYINT(1) NOT NULL DEFAULT 1,
    allow_delivering_sync TINYINT(1) NOT NULL DEFAULT 0
                            COMMENT 'Explicit opt-in: background sync may use a login that can READ messages, which legally delivers them on arrival',
    last_sync_at          DATETIME NULL,
    last_sync_status      ENUM('ok','error','paused') NULL,
    last_sync_message     VARCHAR(500) NULL,
    sync_paused_until     DATETIME NULL COMMENT 'Set after a failed login so cron does not hammer ISDS (repeated bad passwords lock the account)',
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_databoxes_supplier_box (supplier_id, environment, db_id),
    CONSTRAINT fk_databoxes_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS databox_credentials (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    databox_id          INT UNSIGNED NOT NULL,
    purpose             ENUM('sync','action') NOT NULL,
    username            VARCHAR(64) NOT NULL,
    password_enc        TEXT NOT NULL COMMENT 'SecretEncryption (AES-256-GCM); never exposed',
    user_type           VARCHAR(24) NULL COMMENT 'ISDS userType: PRIMARY_USER, ENTRUSTED_USER, ADMINISTRATOR ...',
    user_privils        SMALLINT UNSIGNED NULL COMMENT 'ISDS privilege bitmask: 1 read, 2 read personal, 4 send, 8 list, 16 search, 128 erase vault',
    password_expires_at DATETIME NULL,
    last_check_at       DATETIME NULL,
    last_check_status   ENUM('ok','bad_credentials','locked','error') NULL,
    last_check_message  VARCHAR(500) NULL,

    UNIQUE KEY uq_databox_credentials_purpose (databox_id, purpose),
    CONSTRAINT fk_databox_credentials_box FOREIGN KEY (databox_id) REFERENCES databoxes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS databox_messages (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    databox_id           INT UNSIGNED NOT NULL,
    direction            ENUM('received','sent') NOT NULL,
    dm_id                VARCHAR(20) NOT NULL COMMENT 'ISDS message number',
    sender_box_id        CHAR(7) NULL,
    sender_name          VARCHAR(255) NULL,
    sender_type          SMALLINT NULL COMMENT 'dmSenderType: 10 OVM, 20 PO, 30 PFO, 40 FO, 0 system, finer subtypes since 06/2026',
    recipient_box_id     CHAR(7) NULL,
    recipient_name       VARCHAR(255) NULL,
    annotation           VARCHAR(255) NULL,
    to_hands             VARCHAR(30) NULL,
    sender_ref_number    VARCHAR(50) NULL,
    recipient_ref_number VARCHAR(50) NULL,
    sender_ident         VARCHAR(50) NULL,
    recipient_ident      VARCHAR(50) NULL,
    personal_delivery    TINYINT(1) NOT NULL DEFAULT 0,
    allow_subst_delivery TINYINT(1) NULL COMMENT 'dmAllowSubstDelivery: 0 = the sender (an OVM) disabled delivery by fiction after 10 days',
    message_type         VARCHAR(4) NULL COMMENT 'dmType attribute (commercial postal messages)',
    status               SMALLINT NULL COMMENT 'ISDS dmMessageStatus: 4 delivered to box, 5 delivery by fiction, 6 delivered by login, 7 read, 8 undeliverable ...',
    attachment_size_kb   INT UNSIGNED NULL,
    delivery_time        DATETIME NULL COMMENT 'dmDeliveryTime: put into the recipient box',
    acceptance_time      DATETIME NULL COMMENT 'dmAcceptanceTime: legally delivered (login or fiction)',
    is_tax_office        TINYINT(1) NOT NULL DEFAULT 0,
    tax_office_kind      VARCHAR(20) NULL,
    first_seen_at        DATETIME NOT NULL,
    last_seen_at         DATETIME NOT NULL,
    acknowledged_at      DATETIME NULL COMMENT 'The user dealt with the message (dashboard item cleared)',
    acknowledged_by      BIGINT UNSIGNED NULL,
    delivery_info_xml    MEDIUMTEXT NULL COMMENT 'Unsigned delivery receipt (GetDeliveryInfo dmDelivery)',
    delivery_info_at     DATETIME NULL,
    sent_by              BIGINT UNSIGNED NULL,
    sent_payload_json    JSON NULL COMMENT 'Sent messages: names, sizes and SHA-256 of the attachments as handed to ISDS',

    UNIQUE KEY uq_databox_message (databox_id, direction, dm_id),
    KEY idx_databox_messages_list (databox_id, direction, delivery_time),
    KEY idx_databox_messages_unread (databox_id, direction, acknowledged_at),
    CONSTRAINT fk_databox_messages_box  FOREIGN KEY (databox_id)      REFERENCES databoxes(id) ON DELETE CASCADE,
    CONSTRAINT fk_databox_messages_ack  FOREIGN KEY (acknowledged_by) REFERENCES users(id)     ON DELETE SET NULL,
    CONSTRAINT fk_databox_messages_sent FOREIGN KEY (sent_by)         REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS databox_message_files (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id  BIGINT UNSIGNED NOT NULL,
    kind        ENUM('zfo','delivery_zfo') NOT NULL COMMENT 'zfo = signed message, delivery_zfo = signed delivery receipt',
    content     LONGBLOB NOT NULL,
    size_bytes  INT UNSIGNED NOT NULL,
    sha256      CHAR(64) NOT NULL,
    stored_at   DATETIME NOT NULL,

    UNIQUE KEY uq_databox_message_file (message_id, kind),
    CONSTRAINT fk_databox_message_files_msg FOREIGN KEY (message_id) REFERENCES databox_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_submission_databox_messages (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id INT UNSIGNED NOT NULL,
    message_id    BIGINT UNSIGNED NOT NULL,
    created_by    BIGINT UNSIGNED NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_tsdm (submission_id, message_id),
    CONSTRAINT fk_tsdm_submission FOREIGN KEY (submission_id) REFERENCES tax_submissions(id)  ON DELETE CASCADE,
    CONSTRAINT fk_tsdm_message    FOREIGN KEY (message_id)    REFERENCES databox_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_tsdm_user       FOREIGN KEY (created_by)    REFERENCES users(id)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS databox_outbox (
    token         CHAR(32) NOT NULL PRIMARY KEY COMMENT 'Random one-time confirmation token returned by "prepare"',
    supplier_id   INT UNSIGNED NOT NULL,
    databox_id    INT UNSIGNED NOT NULL,
    user_id       BIGINT UNSIGNED NOT NULL,
    submission_id INT UNSIGNED NULL COMMENT 'Set when the payload is an archived tax filing',
    envelope_json JSON NOT NULL,
    summary_json  JSON NOT NULL,
    created_at    DATETIME NOT NULL,
    expires_at    DATETIME NOT NULL,

    KEY idx_databox_outbox_expires (expires_at),
    CONSTRAINT fk_databox_outbox_box FOREIGN KEY (databox_id) REFERENCES databoxes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS databox_outbox_files (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token      CHAR(32) NOT NULL,
    position   SMALLINT UNSIGNED NOT NULL,
    name       VARCHAR(255) NOT NULL,
    mime       VARCHAR(100) NOT NULL,
    content    LONGBLOB NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256     CHAR(64) NOT NULL,

    UNIQUE KEY uq_databox_outbox_file (token, position),
    CONSTRAINT fk_databox_outbox_files_token FOREIGN KEY (token) REFERENCES databox_outbox(token) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
