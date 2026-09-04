# Prompt 51 — Split action handlers out of pages

Wave 3 (scalability). `--agent=split-actions`. Depends on Prompt 37 (characterization). One page per PR.

---

`daily_orders.php`, `standing_orders_manager.php`, `driver_assignment.php`, `production_center.php` each contain a fat `$_POST['action']` switch with SQL, followed by the HTML. They are pages and JSON APIs at once.

## Read first

- The four pages' pre-header PHP (~240–860 lines each)
- `daily_run_api.php`, `billing_api.php` (existing thin-API shape), `includes/auth.php` `bakery_wants_json`
- `docs/AGENT_DEVELOPMENT_MANUAL.md` cycle 3: pages authorize, validate, call `includes/`, render

## Ship (per page)

1. `includes/<page>_actions.php`: one `bakery_<page>_action_<name>(PDO $db, array $input, array $user): array` per case; pure functions, no echo.
2. `<page>_api.php`: role gate, CSRF, dispatch, JSON. The page keeps a non-JS fallback that calls the same functions.
3. Register `<page>_api.php` in the deploy manifest and the catalog/role gate (Prompt 40).

## Tests

The Prompt 37 characterization suite for that page must stay green unchanged — that is the point.

## Done when

The four pages have no SQL above `require header.php`; each action is a named function with a test.
