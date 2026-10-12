-- 084 — Optional dough temperature on an Intensive loaf.
-- Absent until a baker says they have a thermometer. Editable afterward.

ALTER TABLE sfb_intensive_slots
  ADD COLUMN dough_temp_f DECIMAL(4,1) NULL DEFAULT NULL;
