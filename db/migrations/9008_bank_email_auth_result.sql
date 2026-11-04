-- MyInvoice fork - how the sender of a bank e-mail notice was authenticated.
--
-- Recorded once for every processed message of an IMAP account that requires the authenticity
-- check (bank_email_imap_settings.require_email_auth); NULL = the check did not run.
--   auth_method  'authentication_results' (verdict of the trusted receiving server) | 'dkim_local' (own DKIM check)
--   auth_detail  stable code, e.g. 'dkim_local_pass:airbank.cz', 'dkim_body_hash_mismatch', 'dmarc_pass'

ALTER TABLE bank_email_processed_messages
  ADD COLUMN IF NOT EXISTS auth_method VARCHAR(30) NULL AFTER error_message,
  ADD COLUMN IF NOT EXISTS auth_detail VARCHAR(255) NULL AFTER auth_method;
