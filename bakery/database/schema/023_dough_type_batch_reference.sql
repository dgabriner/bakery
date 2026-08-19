-- Optional reference mix size for dough-type batch planning.
-- When set, theoretical_batches = total_dough_grams / standard_batch_dough_grams.
-- Per-product reference yield (units) = standard_batch_dough_grams / products.weight_grams.

ALTER TABLE dough_types
  ADD COLUMN standard_batch_dough_grams DECIMAL(12,3) NULL DEFAULT NULL
  AFTER product_line_id;
