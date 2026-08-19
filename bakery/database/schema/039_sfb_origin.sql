-- 039 — Real vs synthetic SF Baker origin, plus community contract columns.
-- Synthetics stay in customers for the portal/journal, but wholesale ops
-- queries must exclude sfb_origin = 'synthetic'.

ALTER TABLE customers
  ADD COLUMN sfb_origin ENUM('human','synthetic') NOT NULL DEFAULT 'human' AFTER sf_baker_enabled;

CREATE INDEX idx_customers_sfb_origin ON customers (sfb_origin);

UPDATE customers
   SET sfb_origin = 'synthetic'
 WHERE name IN ('Customer1', 'Customer2');

-- Community circles Prompt 2 will render; keep a single ENUM source of truth.
ALTER TABLE sfb_community_topics
  MODIFY COLUMN category ENUM(
    'starter',
    'formula',
    'fermentation',
    'shaping_baking',
    'general',
    'failures',
    'flours_mills',
    'weekend_schedule'
  ) NOT NULL DEFAULT 'general';

ALTER TABLE sfb_community_topics
  ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER is_locked,
  ADD COLUMN author_kind ENUM('baker','coach') NOT NULL DEFAULT 'baker' AFTER author_customer_id,
  ADD COLUMN author_user_id INT NULL DEFAULT NULL AFTER author_kind;

ALTER TABLE sfb_community_replies
  ADD COLUMN author_kind ENUM('baker','coach') NOT NULL DEFAULT 'baker' AFTER author_customer_id,
  ADD COLUMN author_user_id INT NULL DEFAULT NULL AFTER author_kind;

ALTER TABLE sfb_community_replies
  MODIFY COLUMN author_customer_id INT NULL DEFAULT NULL;

ALTER TABLE sfb_community_topics
  MODIFY COLUMN author_customer_id INT NULL DEFAULT NULL;
