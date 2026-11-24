-- owner-approved-core-column
-- Sales Leads phase 2. Keep the lead neighborhood on the customer.
-- The link back to the lead stays on leads.customer_id.
-- This does not create a standing order or a route assignment.

ALTER TABLE customers
  ADD COLUMN neighborhood VARCHAR(120) NULL;
