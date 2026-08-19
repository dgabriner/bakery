-- 034 — Conversations attached to SF Baker batches.
-- Bakers can leave a comment or ask a question; administrators can reply and
-- resolve the question once it has been addressed.

CREATE TABLE IF NOT EXISTS sfb_batch_messages (
  id INT NOT NULL AUTO_INCREMENT,
  batch_id INT NOT NULL,
  parent_message_id INT NULL DEFAULT NULL,
  author_customer_id INT NULL DEFAULT NULL,
  author_user_id INT NULL DEFAULT NULL,
  author_type ENUM('baker', 'admin') NOT NULL,
  author_name VARCHAR(120) NOT NULL,
  message_type ENUM('comment', 'question') NOT NULL DEFAULT 'comment',
  body TEXT NOT NULL,
  is_resolved TINYINT(1) NOT NULL DEFAULT 0,
  resolved_at DATETIME NULL DEFAULT NULL,
  resolved_by_user_id INT NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_messages_batch_created (batch_id, created_at),
  KEY idx_sfb_messages_parent (parent_message_id),
  KEY idx_sfb_messages_open_questions (message_type, author_type, is_resolved),
  CONSTRAINT fk_sfb_message_batch FOREIGN KEY (batch_id) REFERENCES sfb_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_sfb_message_parent FOREIGN KEY (parent_message_id) REFERENCES sfb_batch_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_sfb_message_customer FOREIGN KEY (author_customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_sfb_message_user FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_sfb_message_resolver FOREIGN KEY (resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
