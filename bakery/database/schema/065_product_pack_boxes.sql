-- Pack List box conversions: pieces that fit in this product's shipping box.
-- Orders stay in pieces. NULL means no box conversion on Pack List.

ALTER TABLE product_pack_yields
  ADD COLUMN pieces_per_box INT NULL DEFAULT NULL AFTER pieces_per_tray;
