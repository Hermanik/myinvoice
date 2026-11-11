-- Data boxes the external accountant may read — fork-private migration, 9xxx range.
--
-- databoxes.accountant_visible   0 = the box is invisible to users whose role is 'accountant' (default: a box
--                                holds legal correspondence and stays private until the owner opts in), 1 = the
--                                accountant may list the box and read its stored messages (read-only).
--
-- Only an admin can change the flag (PUT /api/databox/boxes/{id}); every other write stays admin-only too.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS only. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE databoxes
  ADD COLUMN IF NOT EXISTS accountant_visible TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = users with the accountant role may read this box and its messages (read-only); 0 = hidden from them' AFTER is_active;
