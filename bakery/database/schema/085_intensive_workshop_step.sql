-- 085 — Where the baker is on the current Intensive loaf.
-- formula, mix, bulk, shape, bake, or done. They can step back and edit.

ALTER TABLE sfb_intensive_slots
  ADD COLUMN workshop_step VARCHAR(16) NOT NULL DEFAULT 'formula';
