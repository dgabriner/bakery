# Prompt 35 — Money mutations are transactional

Wave 1 (reliability). `--agent=money-transactions`.

---

`bakery_billing_send_invoice()` marks an order invoiced, sends mail, then records the send — three steps, no transaction, SMTP in the middle. A mail failure after the status flip leaves an "invoiced" order with no send record; a DB failure after SMTP sends a customer an invoice the system does not know about. `includes/square_invoices.php` has no transactions around multi-row writes.

## Read first

- `includes/billing.php` (`bakery_billing_send_invoice`, `bakery_billing_mark_invoiced`, `bakery_billing_record_statement`, send-schema ensure)
- `includes/square_invoices.php`
- `tests/run_invoice_send_tests.php`, `tests/run_square_invoice_tests.php`, `tests/run_customer_billing_tests.php`
- `BAKERY_PRODUCT_CONTEXT.md` §4.9–4.11

## Ship

1. Send invoice = **outbox pattern**: transaction { mark invoiced if not already; insert `billing_invoice_sends` row `status='queued'` } → commit → SMTP → update row `sent` (with provider id) or `failed` (with reason). Status `invoiced` stays true either way; Billing Center shows failed sends as a chip on that row so staff can resend. No phantom "sent".
2. Square: wrap create/publish/record and webhook status updates in `beginTransaction`/`commit`, with the existing "never regress a status" rule.
3. Preserve `MAIL_DRIVER=log` behavior (recorded, not sent).

## Tests

Extend `run_invoice_send_tests.php`: SMTP failure → row `failed`, order still `invoiced`, resend allowed; DB failure before commit → nothing recorded, no mail attempted. Extend `run_square_invoice_tests.php`: partial failure leaves no half-written rows.

## Done when

No path exists where mail is sent and the send row is missing, or the row says sent and no mail attempt happened.
