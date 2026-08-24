# Prompt 26 — Education Payments Connect (frictionless billing for offerings)

Paste this entire file into a **new** agent chat in the `bakery/` workspace. This is stream 4 of 4 in the Community Bread Education Center (`--agent=bread-education`). Build on onboarding (Prompt 25); do not build the batch builder (Prompt 23) or media library (Prompt 24) here.

Sister prompts: `23-bread-education-batch-builder.md`, `24-bread-education-learning-center.md`, `25-home-base-onboarding.md`.

---

You are making paying for education offerings (classes, memberships, kits) **frictionless**: pick an offering, pay through Square, get unlocked — automatically, honestly, and without touching wholesale billing semantics. The rails already exist: Square invoices (`055_square_invoices.sql`, `includes/square_invoices.php`, `includes/square_config.php`) and a signature-checked webhook (`square_webhook.php`, index in `056`).

## Shared contract

- Stack stays **flat PHP + MariaDB**. All Square calls go through `includes/square_config.php` + `includes/square_invoices.php`; webhook stays the only async truth source, signature validation mandatory (HMAC), and it must remain in `bakery_public_scripts()` so Square can reach it.
- **Money honesty**: no card data or bank tokens stored locally — Square references only. Prices snapshot at purchase time; never repriced later by catalog edits. Missing credentials mean recorded-intent rows only — never a pretend paid state (text-comms ledger honesty is the model).
- One attempt leaves exactly one ledger row per state transition; webhook replays are idempotent.
- Wholesale invariants untouched: invoice send from Billing Center, delivery snapshots, COD flows, dated-beats-standing. Education money lives beside invoices, never inside them.
- Synthetics never purchase or hold entitlements. Entitlements attach to human customers only.
- Safety: tests on `bakerysf_test`; sandbox creds only until owner authorizes staging keys via gitignored env files (Twilio card pattern: creds stay out of Git and out of context).
- i18n: every new string in `lang/en.php` and `lang/es.php` under `sfb.*`.

## Read first

- `includes/square_config.php`, `includes/square_invoices.php`, `square_webhook.php`
- `database/schema/055_square_invoices.sql`, `056_square_webhook_invoice_index.sql`
- `billing_center.php` + `tests/run_invoice_send_tests.php` (adjacent semantics to respect)
- `includes/auth.php` public scripts allowlist; `includes/customer_portal.php` gating
- `BAKERY_PRODUCT_CONTEXT.md` §7 billing rows

## Ship

1. **Offerings**: additive migration (`061+`) for education offerings (class/membership/kit) with name, description (i18n), price cents, currency, active flag, and optional entitlement duration. Admin CRUD screen keeps it editable in-app.
2. **Checkout**: buy button → hosted Square checkout link created through the existing include set; customer lands back on a status page that says plainly pending/paid/failed. No local card forms, ever.
3. **Webhook truth**: payment events flip offering-purchase rows pending → paid (→ refunded/canceled), idempotent by event/order id; unknown events logged, never guessed.
4. **Entitlements**: paid state unlocks gated things (courses/media from Prompt 24, cohort invites from Prompt 25) via one small entitlement table keyed customer+offering with expiry support; one helper both checkout and webhook call — no second write path.
5. **Ops visibility**: Billing Center (or a compact education section within it) lists purchases with true states and links to the offering; staff can record offline payments deliberately (marked manual, actor stamped).
6. **Failure honesty**: expired/canceled checkout links say so; retry creates a new attempt row rather than mutating history.

## Constraints

- Do not modify wholesale invoice send semantics, snapshots, or COD flows.
- No subscriptions engine beyond what offerings need; no pricing logic outside the offerings table.
- Live keys only after owner authorization; staging verification happens through Staging Manager like everything else.

## Done when

- On Staging with sandbox keys: signup → pick class → pay → webhook flips paid → course unlocks, hands off no human
- Refund/cancel paths verified end to end; ledger shows one row per transition
- With credentials absent, buying records intent and clearly says unconfigured — nothing pretends
- en/es complete; new suites plus `run_invoice_send_tests.php` green
