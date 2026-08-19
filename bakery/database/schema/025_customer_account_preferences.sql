-- Customer account preferences: delivery instructions and role-specific contacts

ALTER TABLE customers ADD COLUMN delivery_instructions TEXT NULL COMMENT 'Customer-facing delivery/receiving notes for drivers';

ALTER TABLE customers ADD COLUMN ordering_contact_name VARCHAR(100) NULL DEFAULT NULL;
ALTER TABLE customers ADD COLUMN ordering_contact_phone VARCHAR(20) NULL DEFAULT NULL;
ALTER TABLE customers ADD COLUMN ordering_contact_email VARCHAR(100) NULL DEFAULT NULL;

ALTER TABLE customers ADD COLUMN delivery_contact_name VARCHAR(100) NULL DEFAULT NULL COMMENT 'Day-of-delivery contact';
ALTER TABLE customers ADD COLUMN delivery_contact_phone VARCHAR(20) NULL DEFAULT NULL COMMENT 'Day-of-delivery phone';

ALTER TABLE customers ADD COLUMN billing_contact_name VARCHAR(100) NULL DEFAULT NULL COMMENT 'Accounts payable contact';
ALTER TABLE customers ADD COLUMN billing_contact_email VARCHAR(100) NULL DEFAULT NULL;
ALTER TABLE customers ADD COLUMN billing_contact_phone VARCHAR(20) NULL DEFAULT NULL;
