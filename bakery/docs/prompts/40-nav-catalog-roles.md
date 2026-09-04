# Prompt 40 — Navigation catalog drives roles

Wave 2 (mobile navigation). `--agent=nav-catalog-roles`. Prerequisite for 45.

---

`includes/navigation_catalog.php` knows every module and its roles, but role *enforcement* is a second set of hand-kept filename arrays in `includes/auth.php` (`bakery_driver_scripts`, `bakery_baker_scripts`, `bakery_cashier_scripts`). A new page defaults to "any logged-in staff". Managers see 51 catalog items behind More → All tools. `cashier_add_product.php` includes the header but not `nav.php`.

## Read first

- `includes/navigation_catalog.php`, `includes/nav.php`, `includes/header.php`
- `includes/auth.php` `bakery_enforce_request_security` (lines ~1092–1194) and the `*_scripts()` lists
- `tests/run_navigation_tests.php`, `tests/run_auth_tests.php`, `tests/run_cashier_role_tests.php`
- `docs/MODULE_ACCESS_GUIDE.md`, `module_guide.php`

## Ship

1. One source: `bakery_navigation_scripts_for_role($role)` derived from catalog `roles`; the `*_scripts()` functions return from it (keep names for callers). Scripts not in the catalog and not public/portal/diagnostic → **administrator only** (default-deny), with a `bakery_navigation_register_script()` escape hatch for JSON endpoints.
2. Manager More: cap at 8 `everyday` items; the rest behind a searchable "All tools" sheet (filter input, no nesting deeper than one sheet).
3. `cashier_add_product.php` includes `nav.php` like its siblings.
4. `module_guide.php` and `docs/MODULE_ACCESS_GUIDE.md` render/read from the same catalog (baker shows Mix Today).

## Tests

Extend `run_navigation_tests.php`: every root page is either public, portal, or resolvable to at least one role from the catalog; manager primary ≤ 8. `run_auth_tests.php`: an unlisted script is refused for driver/baker/cashier. `run_cashier_role_tests.php`: nav present on Add Product. `run_i18n_tests.php`.

## Done when

Deleting a filename from a `*_scripts()` array is no longer a way to grant or revoke access — the catalog is.
