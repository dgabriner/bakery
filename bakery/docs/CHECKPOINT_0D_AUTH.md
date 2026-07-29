# Checkpoint 0D — Authentication and CSRF

## Delivered

- Tables: `users`, `roles`, `permissions`, `role_permissions` (`database/schema/002_auth.sql`)
- Roles: `administrator`, `manager`, `driver` (permission matrix extensible)
- `includes/auth.php` — login/logout, session idle/absolute expiry, CSRF, role gates
- Web gate in `includes/database.php` after DB connect (all pages using shared DB bootstrap)
- Public exceptions: `login.php`, `health_local.php`
- Diagnostics: administrator only (+ Apache `.htaccess` deny patterns)
- Driver scripts allow administrator/manager/driver
- All other ops pages: administrator/manager
- CSRF on POST/PUT/PATCH/DELETE; `includes/csrf.js` attaches token to `fetch`
- Seed: `scripts/seed_local_users.php`
- Emergency reset: `scripts/reset_local_admin.php` (local only)
- DB user sync: `scripts/sync_local_db_user.php`
- Tests: `tests/run_auth_tests.php`

## Not in this checkpoint

- Full final permission matrix / office/baker/packer/customer roles
- Permanent auth bypass flag
- Weekday/zone fixes (0E)
- `get_driver_orders.php` restore (0E)

## Local seed accounts

Documented in `docs/LOCAL_SETUP.md` (`@local.test` addresses only).
