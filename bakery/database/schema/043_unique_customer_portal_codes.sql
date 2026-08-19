-- Returning customers sign in with their four-digit code, so no code may be reused.
CREATE UNIQUE INDEX uq_customers_portal_code ON customers (portal_code);
