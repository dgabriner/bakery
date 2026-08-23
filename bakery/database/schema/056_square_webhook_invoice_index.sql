-- Repair environments where square_webhook_events was created by the runtime
-- before migration 055 added its invoice lookup index.

ALTER TABLE square_webhook_events
  ADD INDEX idx_square_webhook_invoice (square_invoice_id);
