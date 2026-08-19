-- Route closeout: end-of-route loaded = delivered + returned + wasted.
-- Extends the finished-goods ledger with waste (and delivery custody exit)
-- and records returned/wasted quantities on each driver load line.

ALTER TABLE inventory_movements
  MODIFY COLUMN movement_type
  ENUM('production','count','load','load_correction','return','waste','delivery') NOT NULL;

ALTER TABLE driver_load_items
  ADD COLUMN wasted_quantity INT NOT NULL DEFAULT 0 AFTER returned_quantity;

ALTER TABLE driver_loads
  ADD COLUMN reconciled_at TIMESTAMP NULL DEFAULT NULL AFTER status,
  ADD COLUMN reconciled_by_user_id INT NULL DEFAULT NULL AFTER reconciled_at;
