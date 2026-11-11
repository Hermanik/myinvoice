-- MyInvoice fork - user invitations and the per-user second-factor mode.
--
-- user_invites: one-time links an admin hands to a new (or already existing) user so that the user
-- sets their own password. Only the SHA-256 of the 32 random token bytes is stored. Issuing a new
-- invite for a user invalidates the previous unused ones (UserInviteService), a used or expired
-- row is never accepted again.
--
-- users.mfa_mode: how the user satisfies the second factor.
--   'default' - the usual strong factors (passkey / TOTP)
--   'email'   - a code mailed to the user's own address; it also satisfies a REQUIRED-MFA policy for that
--               user, so no authenticator enrolment is forced. Never allowed for admins (CHECK below,
--               enforced in the application as well - UserMfaMode).

CREATE TABLE IF NOT EXISTS user_invites (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  expires_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at     TIMESTAMP NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by  BIGINT UNSIGNED NULL,       -- the admin who issued the invite
  UNIQUE KEY uq_user_invites_token (token_hash),
  KEY idx_user_invites_user (user_id, used_at),
  CONSTRAINT fk_user_invites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_invites_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS mfa_mode ENUM('default','email') NOT NULL DEFAULT 'default' AFTER totp_enabled;

ALTER TABLE users
  ADD CONSTRAINT IF NOT EXISTS chk_users_mfa_mode_not_admin CHECK (NOT (role = 'admin' AND mfa_mode = 'email'));
