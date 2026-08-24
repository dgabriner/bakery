-- 062 — Bread Education Batch Builder (Prompt 23).
-- Provenance for formulas remixed from shared bake cards, and an optional
-- phase tag so batch questions attach to the step they are about.

ALTER TABLE sfb_formulas
  ADD COLUMN remixed_from_batch_id INT NULL DEFAULT NULL,
  ADD INDEX idx_sfb_formulas_remix_source (remixed_from_batch_id);

ALTER TABLE sfb_batch_messages
  ADD COLUMN phase VARCHAR(20) NULL DEFAULT NULL,
  ADD INDEX idx_sfb_messages_phase (batch_id, phase);
