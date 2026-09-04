# Prompt 50 — Extract inline CSS/JS from the god-pages

Wave 3 (scalability). `--agent=extract-assets`. One page per PR.

---

Sixty root pages carry ~20k lines of inline `<style>` and ~18k of inline `<script>`. The six worst: `route_manager.php` (1654 CSS / 2268 JS), `standing_orders_manager.php` (1414 / 1458), `driver_overview.php` (1206), `driver_assignment.php` (1051 / 1665), `customer_schedule.php` (1086), `standing_routes.php` (988). Inline assets are not cached, not linted, and make every diff a conflict.

## Read first

- `includes/client_cache.php` (`bakery_asset_href` cache-busting), `includes/header.php`
- `scripts/deploy_manifest.ps1` (`css/`, `includes/` globs)
- `tests/run_surface_hygiene_tests.php`, `tests/run_deploy_surface_tests.php`

## Ship (per page)

1. Move `<style>` → `css/<page>.css`; `<script>` → `includes/<page>.js`; load via `bakery_asset_href()`. PHP-templated values in JS become `data-*` attributes or a single `window.__<PAGE>__ = <?= json_encode(...) ?>` block.
2. Zero behavior change. Diff the rendered HTML before/after for one date (`php -S` locally or a render test).
3. Page shell target: < 400 lines of PHP; note the count in the handoff.

## Tests

The page's mapped suites + `run_surface_hygiene_tests.php` (desktop) + `run_deploy_surface_tests.php`. Add a string-assert that the page no longer contains `<style>`.

## Done when

The six pages have no inline `<style>` and no inline `<script>` longer than the JSON bootstrap block.

**Status:** shipped 2026-09-04 — all six pages extracted to `css/<page>.css` + `includes/<page>.js` with `window.__…__` bootstraps. Shell line counts: route_manager 551, standing_orders_manager 1225, driver_overview 619, driver_assignment 1077, customer_schedule 504, standing_routes 355 (PHP logic still on-page; assets only). `tests/run_extract_assets_tests.php`. Staging and Live were not touched.
