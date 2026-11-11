-- Guided tour ("Prohlídka aplikace") state, stored per user so it follows the
-- account across devices and browsers (fork-private migration, 9xxx range).
--
-- tour_status is NULL until the user has finished or skipped the tour; the web
-- client starts the tour automatically on login while it is NULL. Restarting the
-- tour from the menu does not reset it - it only records the outcome again.

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS tour_status ENUM('completed','skipped') NULL
      COMMENT 'NULL = the guided tour was not shown yet',
  ADD COLUMN IF NOT EXISTS tour_finished_at DATETIME NULL
      COMMENT 'UTC time the tour was completed or skipped';
