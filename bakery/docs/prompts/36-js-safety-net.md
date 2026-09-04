# Prompt 36 — Browser safety net

Wave 1 (reliability). `--agent=js-safety-net`.

---

No page listens for `unhandledrejection` or `error`. A driver whose photo upload rejects sees a frozen modal and nothing else. `includes/driver_delivery.js` has at least one `await fetch` outside try/catch (line ~920).

## Read first

- `includes/shell.js`, `includes/csrf.js`, `includes/client_refresh.js`, `includes/staff_alerts.js`
- `includes/driver_delivery.js`, `includes/driver_route_prep.js`, `includes/global_tracking.js`, `includes/portal_orders.js`
- `login_history.php` + `includes/login_audit*.php` (where admins already read client telemetry)

## Ship

1. `includes/shell.js`: `window.addEventListener('unhandledrejection' | 'error')` → `navigator.sendBeacon('client_error_api.php', ...)` with message, stack head, page, build id. On driver/baker/cashier workspaces also show the existing toast pattern with an EN/ES "something failed, try again" string.
2. `client_error_api.php`: login required, CSRF-exempt beacon (POST, same-origin check), rate-limited per session (e.g. 20/min), writes to `login_audit_activity` (or a small `client_errors` table via next `NNN`). Admin view: a "Browser errors" chip/list on Login History.
3. Audit every `await fetch` in `includes/*.js`; each is inside try/catch with a visible status; helpers return `{ok, error}` rather than throwing into UI code.

## Tests

Extend `run_driver_photo_ui_tests.php` / `run_driver_workflow_tests.php` (string asserts on the JS + endpoint auth/rate-limit); `run_login_history_tests.php` if the admin surface changes; `run_i18n_tests.php`.

## Done when

Rejecting the photo upload promise on `driver.php` shows a message, logs a beacon, and the modal can be retried.
