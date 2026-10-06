-- Formalize billing_invoice_sends.failure_reason for Staging/Live schema match.
-- Live already has this column from bakery_billing_ensure_invoice_send_schema()
-- (Mission 35 outbox). Staging did not, which Stop'd the Manager compare.
-- Hosted-gate portable: plain ADD COLUMN (no IF NOT EXISTS). Apply-once via
-- schema_migrations; duplicate-column on Live is accepted after INFORMATION_SCHEMA verify.

ALTER TABLE billing_invoice_sends
  ADD COLUMN failure_reason VARCHAR(255) NULL DEFAULT NULL AFTER status;
