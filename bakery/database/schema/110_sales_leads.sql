-- Sales Leads phase 1. Additive only. The integer leads.id and lead_contacts stay.
-- external_id is the business id (SFL-001). status stays and the app keeps it
-- in step with stage so older counts still run.
-- Phase 2: Won becomes a customer by inserting customers and setting the
-- existing leads.customer_id. This file does not drop that column.
-- Phase 3: a sample is a dated one-time order. sample_daily_order_id points
-- at daily_orders when that order exists. Zone export stays on zones;
-- zone_id is the optional link from the route_fit text.
-- Hosted-gate portable: one additive change per statement.

ALTER TABLE leads
  ADD COLUMN external_id VARCHAR(64) NULL;

ALTER TABLE leads
  ADD COLUMN lead_type VARCHAR(120) NULL;

ALTER TABLE leads
  ADD COLUMN neighborhood VARCHAR(120) NULL;

ALTER TABLE leads
  ADD COLUMN website VARCHAR(255) NULL;

ALTER TABLE leads
  ADD COLUMN contact_channel VARCHAR(255) NULL;

ALTER TABLE leads
  ADD COLUMN contact_verified VARCHAR(255) NULL;

ALTER TABLE leads
  ADD COLUMN current_supplier VARCHAR(255) NULL;

ALTER TABLE leads
  ADD COLUMN cuisine VARCHAR(120) NULL;

ALTER TABLE leads
  ADD COLUMN fit_score TINYINT NULL;

ALTER TABLE leads
  ADD COLUMN fit_reason TEXT NULL;

ALTER TABLE leads
  ADD COLUMN priority VARCHAR(1) NULL;

ALTER TABLE leads
  ADD COLUMN stage ENUM('New','Researched','Contacted','Sampling','Proposal','Won','Lost') NULL;

ALTER TABLE leads
  ADD COLUMN next_action VARCHAR(255) NULL;

ALTER TABLE leads
  ADD COLUMN next_action_date DATE NULL;

ALTER TABLE leads
  ADD COLUMN route_fit TEXT NULL;

ALTER TABLE leads
  ADD COLUMN zone_id INT NULL;

ALTER TABLE leads
  ADD COLUMN source VARCHAR(255) NULL;

ALTER TABLE leads
  ADD COLUMN source_url VARCHAR(512) NULL;

ALTER TABLE leads
  ADD COLUMN last_updated DATE NULL;

ALTER TABLE leads
  ADD COLUMN sample_daily_order_id INT NULL;

CREATE UNIQUE INDEX uq_leads_external_id ON leads (external_id);

CREATE INDEX idx_leads_next_action_date ON leads (next_action_date);

CREATE INDEX idx_leads_stage ON leads (stage);

CREATE INDEX idx_leads_zone_id ON leads (zone_id);

CREATE INDEX idx_leads_sample_daily_order ON leads (sample_daily_order_id);

ALTER TABLE leads
  ADD CONSTRAINT fk_leads_zone_id
  FOREIGN KEY (zone_id) REFERENCES zones (id)
  ON DELETE SET NULL
  ON UPDATE CASCADE;

ALTER TABLE leads
  ADD CONSTRAINT fk_leads_sample_daily_order
  FOREIGN KEY (sample_daily_order_id) REFERENCES daily_orders (id)
  ON DELETE SET NULL
  ON UPDATE CASCADE;

UPDATE leads
SET stage = CASE status
  WHEN 'new' THEN 'New'
  WHEN 'contacted' THEN 'Contacted'
  WHEN 'interested' THEN 'Researched'
  WHEN 'qualified' THEN 'Proposal'
  WHEN 'converted' THEN 'Won'
  WHEN 'closed' THEN 'Lost'
  ELSE 'New'
END
WHERE stage IS NULL;

UPDATE leads
SET last_updated = DATE(updated_at)
WHERE last_updated IS NULL
  AND updated_at IS NOT NULL;

CREATE TABLE IF NOT EXISTS lead_activity (
  id INT NOT NULL AUTO_INCREMENT,
  lead_id INT NOT NULL,
  activity_date DATE NOT NULL,
  user_id INT NULL,
  note TEXT NULL,
  stage_from VARCHAR(32) NULL,
  stage_to VARCHAR(32) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lead_activity_lead (lead_id, activity_date, id),
  CONSTRAINT fk_lead_activity_lead FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE,
  CONSTRAINT fk_lead_activity_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
