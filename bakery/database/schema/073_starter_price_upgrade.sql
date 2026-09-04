-- 073 — Starter catalog price upgrade.
-- Shipped jar $25 → $30. First Loaf Kit $45 → $75 and now includes a banneton.
-- Existing purchases keep price_cents_snapshot. Do not rewrite paid history.

UPDATE sfb_offerings
SET description = 'A living Sour Flour starter jar shipped to you. $30 includes shipping.',
    price_cents = 3000
WHERE title = 'Sourdough Starter — Shipped';

UPDATE sfb_offerings
SET description = 'Living Sour Flour starter, a plastic bench scraper, a baker''s lame, and a banneton. Pickup Tuesday or Friday. Scale, lidded pot, flour, and razor blades: buy from the shops we recommend.',
    price_cents = 7500
WHERE title = 'First Loaf Kit — Bakery Pickup';
