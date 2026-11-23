-- 119 — Mark the staff and house customer rows from the Nov 12 Live export.
-- Account health reads customers.is_internal and does not guess by name.
-- Each UPDATE matches one id and the stored name together. A renamed row
-- is left alone. 66 Bart front is not flagged: that stop still has past
-- Pending orders, and Bakery has not confirmed it is internal.
-- 114 and 115 are Live-only ledger rows. 116 is already on the Live ledger.
-- This file is 119.
-- owner-approved-core-column: the account health list needs this flag.

ALTER TABLE customers
  ADD COLUMN is_internal TINYINT(1) NOT NULL DEFAULT 0;

UPDATE customers
   SET is_internal = 1
 WHERE id = 192
   AND name = 'Baker 4152222222';

UPDATE customers
   SET is_internal = 1
 WHERE id = 200
   AND name = 'Baker 4844101481';

UPDATE customers
   SET is_internal = 1
 WHERE id = 197
   AND name = 'Danny';

UPDATE customers
   SET is_internal = 1
 WHERE id = 198
   AND name = 'SourFlour House Baker';
