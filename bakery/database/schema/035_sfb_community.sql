-- 035 — SF Baker community forum and opt-in batch sharing.
-- A batch stays private until its owner attaches it to a community discussion.

CREATE TABLE IF NOT EXISTS sfb_community_topics (
  id INT NOT NULL AUTO_INCREMENT,
  author_customer_id INT NOT NULL,
  linked_batch_id INT NULL DEFAULT NULL,
  category ENUM('starter', 'formula', 'fermentation', 'shaping_baking', 'general') NOT NULL DEFAULT 'general',
  title VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  is_locked TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_community_topics_recent (created_at),
  KEY idx_sfb_community_topics_category_recent (category, created_at),
  KEY idx_sfb_community_topics_batch (linked_batch_id),
  CONSTRAINT fk_sfb_community_topic_customer FOREIGN KEY (author_customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_sfb_community_topic_batch FOREIGN KEY (linked_batch_id) REFERENCES sfb_batches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sfb_community_replies (
  id INT NOT NULL AUTO_INCREMENT,
  topic_id INT NOT NULL,
  author_customer_id INT NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_community_replies_topic_created (topic_id, created_at),
  CONSTRAINT fk_sfb_community_reply_topic FOREIGN KEY (topic_id) REFERENCES sfb_community_topics(id) ON DELETE CASCADE,
  CONSTRAINT fk_sfb_community_reply_customer FOREIGN KEY (author_customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The existence of a row is the owner's explicit permission to show this
-- batch's formula snapshot, photos, and selected process facts to SF Bakers.
CREATE TABLE IF NOT EXISTS sfb_batch_shares (
  batch_id INT NOT NULL,
  customer_id INT NOT NULL,
  shared_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (batch_id),
  KEY idx_sfb_batch_shares_customer (customer_id),
  CONSTRAINT fk_sfb_batch_share_batch FOREIGN KEY (batch_id) REFERENCES sfb_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_sfb_batch_share_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
