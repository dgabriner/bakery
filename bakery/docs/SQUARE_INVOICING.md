# Square invoicing (Billing Center)

Non-COD Ready deliveries can be billed in Square from Billing Center. COD cash stays on Route Manager.

## Env vars

Set these in Staging `.env` (sandbox) and Live `.env` (production). Never commit tokens.

| Variable | Staging | Live |
| --- | --- | --- |
| `SQUARE_ENV` or `SQUARE_ENVIRONMENT` | `sandbox` (default on Staging unless you force production) | `production` |
| `SQUARE_ACCESS_TOKEN` | Sandbox seller token | Production seller token |
| `SQUARE_LOCATION_ID` | Sandbox location | Live: Sour Flour `AWVYVDS9957DC` |
| `SQUARE_APPLICATION_ID` | optional | optional |
| `SQUARE_WEBHOOK_SIGNATURE_KEY` | from Square Developer Dashboard | same, production app |
| `SQUARE_WEBHOOK_NOTIFICATION_URL` | optional override of the public webhook URL used for signature checks | `https://bakery.sourflour.org/bake/square_webhook.php` |

Check: `php scripts/test_square_connection.php`

Apply schema: `php scripts/run_migrations.php` (adds `055_square_invoices`; `056_square_webhook_invoice_index` repairs tables created by the earlier runtime path). Reversible by dropping `daily_orders.square_*`, `customers.square_customer_id`, and `square_webhook_events`.

## Webhook

1. Square Developer Dashboard, production or sandbox app, Webhooks.
2. URL: Staging `https://staging.sourflour.org/square_webhook.php` (confirm BASE_URL). Live `https://bakery.sourflour.org/bake/square_webhook.php`.
3. Events: `invoice.published`, `invoice.updated`, `invoice.payment_made`, `invoice.canceled`.
4. Paste the signature key into `SQUARE_WEBHOOK_SIGNATURE_KEY`.
5. New root PHP files can 404 on Staging if the deploy manifest missed them. This PR adds `square_webhook.php` and `billing_api.php` to `scripts/deploy_manifest.ps1`. Confirm the file is on the host after sync.

If the webhook is delayed, use **Refresh Square status** on the invoice detail (poll fallback).

## Billing Center use

1. Filter Collection = Invoice (non-COD).
2. Open a Ready / already invoiced confirmed delivery.
3. Optional: Test recipient for this send only (example `danny@sourflour.org`). Leave empty to use the customer billing email.
4. Optional: Create draft only.
5. Send Square invoice. Pay page should offer card, Cash App Pay, and bank ACH.
6. OS stores Square invoice id, order id, public URL, and status.
7. Second click reuses the stored Square invoice. It does not create another one.
8. Missing customer email blocks publish with a visible error unless a test recipient is set.
9. COD rows show a note and no Square send button. The API also rejects COD.

Portal **Send invoice** (MAIL_DRIVER=log) is unchanged. Square email is sent by Square, not OS SMTP.

## Zazie / Aug 12 Ready invoice (pilot)

- OS `customer_id=24` (Zazie, 941 Cole St). Observed snapshot prices: Dinner Roll $0.50, Pan de Mie $4.50.
- Ready example: `INV-20260812-02183` Wednesday $96 (192 rolls).
- OS has no email on Zazie. Real contacts from ops: `mario@zaziesf.com`, `jen@zaziesf.com`. For a format test, use the test recipient field (`danny@sourflour.org`) rather than writing a fake email onto the customer.
- Prefer Staging sandbox first. Production Square was already proven manually (invoice #000384). Do not auto-email every Ready invoice.

## Payment methods

Create payload turns on `card`, `cash_app_pay`, and `bank_account`. Gift card and BNPL stay off.
