-- Signed ZFO import into the data boxes — fork-private migration, 9xxx range.
--
-- A box that is watched by notification e-mails only never sees sent messages, messages from before the e-mail setup, or
-- what happened to a message after its notice (opened in the portal, delivered by fiction). The owner can download the
-- signed message / delivery receipt (ZFO) in the portal and upload it to the application; such a row gets the new source
-- 'zfo' (the signed data are authoritative, the application verified the signature before it stored anything).
--
-- databox_messages.source   'isds' (envelope list) | 'email' (notification e-mail) | 'zfo' (uploaded signed ZFO)
--
-- Idempotence: MODIFY COLUMN only (the same definition again is a no-op). Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE databox_messages
  MODIFY COLUMN source ENUM('isds','email','zfo') NOT NULL DEFAULT 'isds'
    COMMENT 'email = created from a notification e-mail, zfo = created / completed from an uploaded signed ZFO, isds = confirmed by an envelope list (replaces email and keeps precedence over zfo)';
