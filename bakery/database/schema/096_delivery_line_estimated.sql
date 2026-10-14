-- A driver confirms one delivered total. Spreading that total across products
-- invents per-product counts. Short totals may still be split, and those lines
-- are marked estimated. Totals above the order keep each product at its ordered
-- count and store the extra pieces on the order, unallocated.
-- The flag backfill only sets delivered_quantity_estimated. It does not change
-- delivered_quantity or line_total.
-- owner-approved-core-column: staff need a visible estimated flag and an unallocated piece total so Customer Record and Billing do not treat a proportional split as a counted product.
-- schema_migrations tracks apply-once; do not re-run after recorded.
-- File number 096. 091 through 093 are already used. 094 and 095 are reserved.

ALTER TABLE daily_order_items
  ADD COLUMN delivered_quantity_estimated TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE daily_orders
  ADD COLUMN unallocated_delivered_pieces INT NOT NULL DEFAULT 0;

UPDATE daily_order_items AS doi
INNER JOIN (
    SELECT spread.daily_order_id
    FROM (
        SELECT do.id AS daily_order_id
        FROM daily_orders AS do
        INNER JOIN daily_order_items AS i ON i.daily_order_id = do.id
        WHERE do.delivered_pieces IS NOT NULL
        GROUP BY do.id, do.delivered_pieces
        HAVING COALESCE(SUM(i.delivered_quantity), 0) = do.delivered_pieces
           AND COALESCE(SUM(i.quantity), 0) <> do.delivered_pieces
           AND SUM(CASE WHEN i.delivered_quantity IS NULL THEN 1 ELSE 0 END) = 0
    ) AS spread
) AS matched ON matched.daily_order_id = doi.daily_order_id
SET doi.delivered_quantity_estimated = 1;
