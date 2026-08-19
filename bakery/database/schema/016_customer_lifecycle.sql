-- Customer lifecycle and lead conversion linkage.
ALTER TABLE customers
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER default_pan_dulce_price,
  ADD COLUMN inactive_at TIMESTAMP NULL DEFAULT NULL AFTER is_active,
  ADD COLUMN inactive_reason VARCHAR(255) NULL DEFAULT NULL AFTER inactive_at,
  ADD KEY idx_customers_is_active (is_active);

ALTER TABLE leads
  ADD COLUMN customer_id INT NULL AFTER status,
  ADD KEY idx_leads_customer_id (customer_id),
  ADD CONSTRAINT fk_leads_customer_id
    FOREIGN KEY (customer_id) REFERENCES customers(id)
    ON DELETE SET NULL ON UPDATE CASCADE;
