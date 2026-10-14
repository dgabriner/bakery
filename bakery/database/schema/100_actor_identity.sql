-- Who performed an action: human, bot, system, or customer.
-- Numbers 100 and 101 are reserved for this change. 091–099 belong to other work.
-- Hosted-gate portable: one additive change per statement.

ALTER TABLE users
  ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0;

UPDATE users
SET is_bot = 1
WHERE display_name IN ('New Bot', 'Cursor Agent');

ALTER TABLE operational_events
  ADD COLUMN actor_type VARCHAR(16) NULL DEFAULT NULL;

ALTER TABLE login_audit
  ADD COLUMN actor_type VARCHAR(16) NULL DEFAULT NULL;

ALTER TABLE login_audit_activity
  ADD COLUMN actor_type VARCHAR(16) NULL DEFAULT NULL;

UPDATE operational_events oe
LEFT JOIN users u ON u.id = oe.actor_user_id
SET oe.actor_type = CASE
  WHEN u.is_bot = 1 OR u.display_name IN ('New Bot', 'Cursor Agent') THEN 'bot'
  WHEN oe.actor_role = 'customer' THEN 'customer'
  WHEN oe.actor_user_id IS NULL AND LEFT(oe.event_type, 7) = 'portal_' THEN 'customer'
  WHEN oe.actor_user_id IS NULL THEN 'system'
  ELSE 'human'
END
WHERE oe.actor_type IS NULL;

UPDATE login_audit la
LEFT JOIN users u ON u.id = la.user_id
SET la.actor_type = CASE
  WHEN u.is_bot = 1 OR u.display_name IN ('New Bot', 'Cursor Agent') THEN 'bot'
  WHEN la.auth_type = 'customer' THEN 'customer'
  WHEN la.user_id IS NULL AND la.outcome = 'failure' THEN 'human'
  WHEN la.user_id IS NULL THEN 'system'
  ELSE 'human'
END
WHERE la.actor_type IS NULL;

UPDATE login_audit_activity laa
JOIN login_audit la ON la.id = laa.login_audit_id
LEFT JOIN users u ON u.id = la.user_id
SET laa.actor_type = CASE
  WHEN u.is_bot = 1 OR u.display_name IN ('New Bot', 'Cursor Agent') THEN 'bot'
  WHEN la.auth_type = 'customer' THEN 'customer'
  WHEN la.user_id IS NULL THEN 'system'
  ELSE 'human'
END
WHERE laa.actor_type IS NULL;
