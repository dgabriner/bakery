-- Who recorded a pickup and who closed the route.
-- load_source manager: a person saved the pickup and finished goods were reserved.
-- load_source implicit: the system recorded delivered units and did not reserve stock.
-- closeout_source is manager, driver, or auto. NULL until the route is reconciled.
-- 114 and 115 are Live-only ledger rows, so this file is 116.

ALTER TABLE driver_loads
  ADD COLUMN load_source VARCHAR(16) NOT NULL DEFAULT 'manager',
  ADD COLUMN closeout_source VARCHAR(16) NULL DEFAULT NULL;
