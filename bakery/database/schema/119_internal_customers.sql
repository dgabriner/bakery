-- 119 — Mark staff and house rows that are not wholesale customers.
-- Account health reads customers.is_internal. The name backfill matches
-- bakery_account_health_internal_names() in includes/account_health.php.
-- 114 and 115 are Live-only ledger rows. 116, 117, and 118 are already
-- taken, so this file is 119.
-- owner-approved-core-column: the account health list needs a stored flag
-- for internal rows whose names are not in that helper.

ALTER TABLE customers
  ADD COLUMN is_internal TINYINT(1) NOT NULL DEFAULT 0;

UPDATE customers
   SET is_internal = 1
 WHERE LOWER(TRIM(name)) IN ('baker', 'danny', 'house baker', 'bart front');
