# Prompt 33 — Edge entrypoints behind the gate

Wave 1 (reliability). `--agent=edge-entrypoints`.

---

Auth is centralized in `bakery_enforce_request_security()` — but only for scripts that load `includes/database.php`. Four root files never do: `oauth_setup.php`, `oauth_callback.php`, `setup_directories.php` (creates directories on request), and `assets/api/get_route.php` (dies because it never defines `ACCESS_ALLOWED`). `ping.php` prints `__DIR__` and the PHP version to anyone.

## Read first

- `includes/auth.php` (`bakery_public_scripts`, `bakery_diagnostic_scripts`, `bakery_wants_json`, `bakery_enforce_request_security`)
- `oauth_setup.php`, `oauth_callback.php`, `setup_directories.php`, `ping.php`, `assets/api/get_route.php`
- `tests/run_auth_tests.php`, `tests/run_deploy_surface_tests.php`, `tests/run_navigation_tests.php`

## Ship

1. `oauth_setup.php`, `oauth_callback.php`, `setup_directories.php`: define `ACCESS_ALLOWED`, load `config.php` + `database.php`, and appear in `bakery_diagnostic_scripts()` so they are administrator-only. OAuth callback must still accept Google's redirect for a signed-in administrator.
2. `ping.php`: keep the public liveness answer; drop path, PHP version, and file sizes unless `IS_LOCAL`.
3. `assets/api/get_route.php`: either fix (`ACCESS_ALLOWED` + route-role gate) or delete and remove from the deploy manifest. Grep callers first.
4. `bakery_wants_json()`: any script ending in `_api.php` is JSON.
5. Tests: assert each file loads the gate; assert `ping.php` output lacks `__DIR__` outside local.

## Done when

No root PHP file reachable on Live skips `bakery_enforce_request_security()` except the documented public list.
