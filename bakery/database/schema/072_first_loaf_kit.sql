/* 072 — First Loaf Kit (bakery pickup only).
   Living starter + bench scraper + lame. Same Tue/Fri tote as starter jars.
   Blades, pots, scales: recommend elsewhere. Money rides Square snapshots. */

ALTER TABLE sfb_starter_jar_orders
  ADD COLUMN pack_kind ENUM('jar', 'first_loaf_kit') NOT NULL DEFAULT 'jar'
    AFTER fulfillment;

INSERT IGNORE INTO sfb_offerings (title, description, price_cents, currency, kind, sort_order) VALUES
  (
    'First Loaf Kit — Bakery Pickup',
    'Living Sour Flour starter, a plastic bench scraper, and a baker''s lame. Pickup Tuesday or Friday. Scale, lidded pot, flour, and razor blades: buy from the shops we recommend.',
    4500,
    'USD',
    'kit',
    12
  );
