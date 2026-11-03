-- Starred sidebar items ("Oblíbené"), stored per user so they follow the
-- account across devices. JSON array of internal route paths, e.g.
-- ["/invoices", "/stats"]; NULL = no favorites. The server only normalises
-- the list, the client maps it onto the menu the user is allowed to see.

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS nav_favorites JSON NULL;
