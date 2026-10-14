-- Field-level audit trail: who changed what, and the value before and after.
-- Staff login codes are never stored here. Writers record the words "code changed".
-- Numbers 091-097 are reserved by other work. This table is 098.
-- Billing migration 022 already created audit_log (action, entity, details).
-- This trail is a separate table so those billing rows stay untouched.

CREATE TABLE IF NOT EXISTS field_audit (
  id BIGINT NOT NULL AUTO_INCREMENT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor_user_id INT NULL DEFAULT NULL,
  actor_type ENUM('human', 'bot', 'system', 'customer') NOT NULL,
  entity_type VARCHAR(64) NOT NULL,
  entity_id BIGINT NOT NULL,
  action VARCHAR(32) NOT NULL,
  field VARCHAR(64) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  ip VARCHAR(45) NULL DEFAULT NULL,
  user_agent VARCHAR(512) NULL DEFAULT NULL,
  request_path VARCHAR(1024) NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_field_audit_created (created_at),
  KEY idx_field_audit_actor (actor_user_id, created_at),
  KEY idx_field_audit_entity (entity_type, entity_id),
  KEY idx_field_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
