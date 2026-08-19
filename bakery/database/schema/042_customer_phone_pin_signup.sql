-- Phone + 4-digit PIN account creation for the customer baking portal.
-- PINs for new accounts are password hashes; portal_code stays for legacy logins.
ALTER TABLE customers
  ADD COLUMN portal_phone_key CHAR(10) NULL DEFAULT NULL AFTER portal_phone,
  ADD COLUMN portal_code_hash VARCHAR(255) NULL DEFAULT NULL AFTER portal_code;

CREATE UNIQUE INDEX uq_customers_portal_phone_key ON customers (portal_phone_key);
