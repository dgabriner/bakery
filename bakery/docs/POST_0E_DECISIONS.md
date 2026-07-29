# Post-0E Decisions — Coordinator Guide

**Date:** 2026-07-28  
**Context:** Checkpoint 0E is complete. These items require explicit human approval before any agent proceeds.

---

## 1. Git tracking strategy for untracked app pages

**Current state:** Only ~40 bakery files are tracked in git. Canonical application pages (`index.php`, `customers.php`, `driver_list.php`, `production.php`, etc.) exist on disk but are **untracked**. Backup/debug/Copy variants are catalogued in [QUARANTINE_INVENTORY.md](QUARANTINE_INVENTORY.md).

### Recommended approach (phased)

| Phase | Action | Rationale |
|-------|--------|-----------|
| **Phase A** | Track canonical ops pages only — one file per function, no `*backup*`, `*fixed*`, `*Copy*`, `debug*`, `test_*` | Gives version control over the live app without importing quarantine clutter |
| **Phase B** | Track shared includes (`includes/nav.php`, `includes/common_functions.php`, etc.) and `css/` | Required for coherent diffs on UI changes |
| **Phase C** | Leave quarantine files untracked; human review before any deletion | Matches 0A–0E safety policy |
| **Phase D** | Add `.gitignore` entries for `uploads/`, PII SQL dumps, `.htaccess.bak` | Prevent accidental commits |

### Canonical pages to track (suggested first batch)

```
index.php, customers.php, customer_overview.php, customer_schedule.php
daily_orders.php, standing_orders.php, standing_orders_manager.php
bread_distribution.php, production.php, pack_list.php
driver.php, driver_list.php, driver_assignment.php, driver_overview.php
get_driver_orders.php, get_customer_order_details.php, complete_delivery.php
products.php, ingredients.php, formulas.php, dough_types.php
zones.php, leads.php, orders.php, daily_route.php, standing_routes.php
route_manager.php, map.php, generate_invoice.php
includes/nav.php, includes/common_functions.php, includes/footer.php
css/
```

**Do not track yet:** all files listed in QUARANTINE_INVENTORY.md.

---

## 2. Weekday data migration (Sunday 0 vs 7)

**Status:** NOT authorized. Documented in characterization findings.

**When ready to authorize:**

1. Audit all surfaces using `day_of_week` (generate, pack_list, bread_distribution UI, standing_orders, production, fixtures).
2. Choose canonical encoding (recommend **7 = Sunday** to match standing_orders/fixtures).
3. Write migration SQL + update PHP conversion logic + update characterization test expectations.
4. Run full test suite; deploy only after green.

**Risk:** Production data may have mixed encodings. Requires production DB audit before migration.

---

## 3. Zone schema migration (`zone_id` column)

**Status:** NOT authorized. Code fix (name-based join) is sufficient for local ops.

**When ready to authorize:**

1. Add `customers.zone_id` FK to `zones.id`.
2. Backfill from text `customers.zone` → `zones.name` match.
3. Update all queries to use FK; deprecate text column.
4. Characterization tests for zone filter/join.

---

## 4. Production deploy

**Status:** NOT authorized.

**Prerequisites before deploy:**

- [ ] Canonical pages tracked in git
- [ ] Auth/CSRF verified on all protected endpoints in production-like config
- [ ] Local test suite green (characterization + auth)
- [ ] Credential rotation runbook followed ([CREDENTIAL_ROTATION_RUNBOOK.md](CREDENTIAL_ROTATION_RUNBOOK.md))
- [ ] `.env` / Apache env vars configured on DreamHost (no hardcoded prod creds in git)
- [ ] Diagnostic/debug scripts blocked via `.htaccess` + auth administrator gate
- [ ] Human sign-off on Sunday encoding behavior (known bug or fixed)

---

## 5. Quarantine cleanup

**Status:** Human review required. See [QUARANTINE_INVENTORY.md](QUARANTINE_INVENTORY.md).

**Recommended order:**

1. Confirm canonical page for each function (e.g. `generate_invoice.php` vs `simple_invoice.php`).
2. Archive or delete debug/test scripts after auth hardening is live in production.
3. Remove `*backup*`, `*Copy*`, `*_fixed*` variants once canonical is verified.
4. Never delete PII SQL dumps without credential rotation.

---

## Decision log

| Decision | Status | Date | Notes |
|----------|--------|------|-------|
| Close Checkpoint 0E | Approved | 2026-07-28 | Tests green; commits landed |
| Track canonical app pages | Pending | — | Awaiting coordinator |
| Weekday migration | Pending | — | Blocked |
| Zone schema migration | Pending | — | Blocked |
| Production deploy | Pending | — | Blocked |
| Quarantine deletion pass | Pending | — | Human review only |
