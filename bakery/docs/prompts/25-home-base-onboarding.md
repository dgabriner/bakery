# Prompt 25 — Home Base Onboarding (clean pipeline into the system)

Paste this entire file into a **new** agent chat in the `bakery/` workspace. This is stream 3 of 4 in the Community Bread Education Center (`--agent=bread-education`). Do not build the batch builder (Prompt 23), media library (Prompt 24), or payments (Prompt 26) here — but design the door they all walk through.

Sister prompts: `23-bread-education-batch-builder.md`, `24-bread-education-learning-center.md`, `26-education-payments-connect.md`.

---

You are building one clean front door: a stranger lands, signs up on a phone in under a minute, and lands in a welcoming **home base** already connected to the parts they need — learning center, batch builder, community — with zero staff touch. Wholesale customers keep their existing portal path.

## Shared contract

- Stack stays **flat PHP + MariaDB**. Reuse `includes/auth.php` patterns: CSRF on every POST, server-side `bakery_require_role`, public-script allowlist (`bakery_public_scripts()`) for anything pre-login.
- Signup rails exist: phone-PIN signup (`database/schema/042_customer_phone_pin_signup.sql`, `tests/run_customer_phone_pin_signup_tests.php`), QR login (`qr_login.php`, 029/043), portal gating (`includes/customer_portal.php`, portal allowlists). Extend; do not invent a parallel identity system.
- SF Baker access is `bakery_sfb_require_access($db)` over `customers.sfb_origin`; new humans must be labeled Real from birth. Synthetics never pass this door.
- **Hard rule**: signup/onboarding never creates standing orders, zones, routes, or invoices for anyone. Education access is not an order.
- Safety: tests on `bakerysf_test`. Local/test DB only until the owner authorizes staging/live promotion through Staging Manager.
- i18n: every new string in `lang/en.php` and `lang/es.php` under `sfb.*`.

## Read first

- `customer_login.php`, `qr_login.php`, `includes/auth.php` (`bakery_public_scripts`, request security)
- `tests/run_customer_phone_pin_signup_tests.php` + schema `042_customer_phone_pin_signup.sql`
- `includes/customer_portal.php` (portal scripts allowlist + gating), `includes/sf_baker.php` (`bakery_sfb_require_access`, `bakery_sfb_ensure_starter`)
- `sfb_dashboard.php` (becomes the post-signup home base)
- `BAKERY_PRODUCT_CONTEXT.md` §7 auth + portal rows

## Ship

1. **Public landing**: one page stating what the Center is, with three honest doors — Learn (free lesson preview), Join the room (community signup), Wholesale login (existing portal). No fake marketing copy; real photos of bread are fine.
2. **One-minute signup**: phone number → PIN → name, reusing the phone-PIN flow; education intent captured as a simple preference (learn-only vs learn+share) that defaults safely. Locale respected end to end.
3. **Access wiring**: on success the customer row carries portal access plus SF Baker enablement and `sfb_origin=real`; session lands directly in home base. No staff approval step for learn-only humans; coaches can still gate sharing later.
4. **Home base first-run**: `sfb_dashboard.php` greets first-time bakers with three next actions — set up my starter (`bakery_sfb_ensure_starter`), pick a first formula template, open Lesson 1 — each a single tap. Returning bakers keep today's dashboard.
5. **Invitations**: staff can mint invite codes (class cohorts, friend links) that carry an intent tag through signup so cohorts can be recognized later without a new module.

## Constraints

- The landing/signup pages are the only new public surfaces; everything else sits behind the existing gates.
- Rate-limit and audit signup like the existing login surfaces (login_audit patterns).
- Never auto-enroll anyone in paid offerings (that is Prompt 26); free preview content only.
- No wholesale data (orders, routes, pricing) visible to education-only accounts.

## Done when

- A stranger with only a phone goes landing → signup → starter created → first formula chosen → Lesson 1 open, in minutes, no staff touch
- Every new human shows origin Real and correct locale
- Wholesale logins behave exactly as before
- en/es complete; `run_customer_phone_pin_signup_tests.php`, `run_auth_tests.php`, sfb suites green
