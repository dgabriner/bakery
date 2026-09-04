# Prompt 44 — Manager phone: Routes and Closeout without route_manager.php

Wave 2 (mobile navigation). `--agent=manager-phone-closeout`. Depends on Prompt 50 extracting `route_manager.php` assets (optional but easier).

---

The manager phone shell (`manager.php` → `includes/manager_phone.php`) is good, but its Routes and Missed CTAs deep-link to `route_manager.php` (1654 inline CSS lines, 2268 inline JS lines) and `route_closeout.php`, which are desktop pages. A manager walking the floor cannot close a route from a phone.

## Read first

- `includes/manager_phone.php`, `css/manager_phone.css`, `manager.php` (phone early-exit ~559–596)
- `includes/route_manager.php` (library), `route_closeout.php`, `includes/product_inventory.php` (`bakery_inventory_reconcile_driver_load`)
- `tests/run_manager_phone_tests.php`, `tests/run_route_manager_cash_tests.php`, `tests/run_route_manager_pickup_tests.php`

## Ship

1. Routes tab: per-driver cards (stops done/left, COD collected, open load) with one action: **Close route** → sheet showing loaded / delivered / returned / waste with the van-math line and a Confirm button that calls `bakery_inventory_reconcile_driver_load` exactly as `route_closeout.php` does.
2. Missed tab keeps the exception desk (Prompt 11); failed-stop cards stay.
3. `route_manager.php` and `route_closeout.php` get `.manager-desktop-only` on their dense sections; on ≤720px they show a one-line pointer back to Manager Mode.

## Constraints

One write path: reuse the closeout helper; do not duplicate reconciliation. No new page. EN + ES.

## Tests

Extend `run_manager_phone_tests.php` (renders, links, sheet markup) and `run_route_manager_cash_tests.php` (phone close = desktop close, same movements). Van math invariant asserted by name.

## Done when

A manager on a 375px phone closes a route in two taps and never lands on `route_manager.php`.
