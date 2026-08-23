---
name: close-a-loop
description: >-
  Close an existing Sour Flour OS bakery-day loop on a current screen. Use when
  adding a feature, fixing an open loop, or tempted to create a new page,
  module, dashboard, or ticketing product.
---

# Close a loop

The app has too many screens. After the user's action, something must carry the work forward. If the answer is "memory," that is the bug.

## Playbook

1. `php scripts/agent_homebase.php brief --agent=SLUG --json` — use `mission_packet`. Read `docs/AGENT_DEVELOPMENT_MANUAL.md`.
2. Read the happy path on the **existing** screen(s) in the packet. Do not add a top-level page.
3. Put shared writes in `includes/`. Page scripts authorize → validate → call helper → render/redirect.
4. Surface a chip or inline action where the decision already happens.
5. CSRF on POSTs. `bakery_require_role` on the server. Menu hiding is not security.
6. Add keys to `lang/en.php` **and** `lang/es.php`.
7. Run the packet's `tests/run_*.php` (see the `test-gate` skill). Isolated `bakerysf_test` only.
8. Handoff with the eight §10 fields. Pin lasting choices `--column=decided`.

## Do not

- Invent a second OS, ERP module, or ticketing product.
- Rebuild a workflow because the controller is large.
- Price historical invoices from live `products.price`.
- Hide a still-true operational fact when completing exception work.
- Brief from `docs/archive/`.
