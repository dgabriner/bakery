-- A dated order a manager emptied on purpose must stay empty.
-- standing_fill_blocked stops standing generation from copying lines back.
-- owner-approved-core-column: daily_orders needs a flag so an emptied dated order is not treated as a missing shell.
-- Existing empty dated orders that still have standing for that weekday are
-- marked now, including orders emptied before this column existed.
-- schema_migrations tracks apply-once; do not re-run after recorded.

ALTER TABLE daily_orders
  ADD COLUMN standing_fill_blocked TINYINT(1) NOT NULL DEFAULT 0;

UPDATE daily_orders do
LEFT JOIN daily_order_items doi
  ON doi.daily_order_id = do.id AND doi.quantity > 0
SET do.standing_fill_blocked = 1
WHERE doi.id IS NULL
  AND EXISTS (
    SELECT 1
    FROM standing_orders so
    WHERE so.customer_id = do.customer_id
      AND so.quantity > 0
      AND (
        so.day_of_week = WEEKDAY(do.order_date) + 1
        OR (WEEKDAY(do.order_date) = 6 AND so.day_of_week = 0)
      )
  );
