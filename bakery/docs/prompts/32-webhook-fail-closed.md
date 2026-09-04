# Prompt 32 — Webhooks fail closed

Wave 1 (reliability). `--agent=webhook-fail-closed`.

---

`square_webhook.php` accepted any POST when `SQUARE_WEBHOOK_SIGNATURE_KEY` was empty. A forged `invoice.payment_made` could mark a wholesale invoice paid or unlock an education purchase. Payment truth must be signature-checked or refused.

## Read first

- `square_webhook.php`, `includes/square_invoices.php` (`bakery_square_webhook_valid`)
- `twilio_webhook.php`, `includes/twilio_config.php`
- `tests/run_square_invoice_tests.php`, `tests/run_text_comms_tests.php`
- `BAKERY_PRODUCT_CONTEXT.md` §4.11, bread-education "Money honesty" invariant

## Ship

1. Square: when the signature key is not configured, log once and answer `503 {"ok":false,"error":"webhook_unconfigured"}`; never process the payload. Keep the existing 403 for bad signatures.
2. Twilio: keep validation on by default whenever an auth token exists; refuse unsigned inbound when validation is on.
3. Negative tests: unsigned POST with empty key → 503, nothing written; good signature still processed.

## Constraints

Do not change invoice or education handlers. Do not add a bypass flag for staging — staging uses the sandbox signature key.

## Done when

Both webhook suites green; a curl with no signature and no key gets 503 and writes no rows.
