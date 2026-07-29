# Quarantine Inventory — Bakery Manager / Sour Flour OS

**Date:** 2026-07-28  
**Checkpoint:** 0E wave (Agent 4)  
**Policy:** **DO NOT DELETE** any file listed here during modernization checkpoints 0A–0E. Human review required before any removal.

This inventory is **evidence-based** (scanned on disk under `bakery/`). It lists backup copies, experimental variants, diagnostics, test harnesses, orphan invoice scripts, and PII-bearing SQL — not the canonical application pages.

**Related docs:** [CHECKPOINT_0A_REPOSITORY_CLASSIFICATION.md](CHECKPOINT_0A_REPOSITORY_CLASSIFICATION.md), [CREDENTIAL_ROTATION_RUNBOOK.md](CREDENTIAL_ROTATION_RUNBOOK.md), [CURRENT_STATE.md](CURRENT_STATE.md)

---

## Summary counts (by category)

| Category | Count | Default action |
|----------|------:|----------------|
| Backup / `.bak` | 3 | `review` |
| Fixed / optimized / working variants | 4 | `review` |
| Duplicate / Copy | 1 | `review` |
| Debug scripts | 8 | `delete-later-human-only` (after auth + block rules) |
| Test / diagnostic pages | 19 | `delete-later-human-only` |
| Orphan / alternate invoice scripts | 5 | `review` (pick one canonical later) |
| Experimental alternate UI | 1 | `review` |
| PII / production data SQL | 6 | `keep` gitignored; never commit as-is |
| Credential-exposure utilities (disabled or legacy) | 1 | `keep` until audited |

**Excluded from quarantine (legitimate):** `tests/run_auth_tests.php`, `tests/run_characterization.php`, `tests/harness.php`, `scripts/*`, `health_local.php`, canonical pages (`index.php`, `driver_list.php`, etc.).

---

## Backup files

| Path | Why quarantined | Recommended later action |
|------|-----------------|--------------------------|
| `bread_distribution_backup.php` | Pre-optimization snapshot of bread distribution UI/logic | `review` — diff against `bread_distribution.php` before any delete |
| `product_distribution_backup.php` | Backup copy of product distribution page | `review` |
| `.htaccess.bak` | Prior Apache rules snapshot | `review` — merge useful rules into live `.htaccess` first |

---

## Fixed / optimized / working variants

| Path | Why quarantined | Recommended later action |
|------|-----------------|--------------------------|
| `bread_distribution_fixed.php` | Alternate fix attempt for bread distribution | `review` — may contain useful logic; do not run in prod |
| `bread_distribution_optimized.php` | Performance experiment variant | `review` |
| `generate_invoice_working.php` | Working-copy invoice generator (parallel to canonical) | `review` |
| `standing_routes - Copy.php` | Windows duplicate of `standing_routes.php` | `delete-later-human-only` after confirming no unique logic |

---

## Debug scripts

| Path | Why quarantined | Recommended later action |
|------|-----------------|--------------------------|
| `debug.php` | Generic debug entry point | `delete-later-human-only` — block public access first |
| `simple-debug.php` | Simplified debug page; historically had inline DB credentials (0A) | `delete-later-human-only` |
| `debug_order_details.php` | Order inspection debug; historically had inline DB credentials (0A) | `delete-later-human-only` |
| `debug_driver_assignment.php` | Driver assignment debugging | `delete-later-human-only` |
| `debug_driver_interface.php` | Driver UI debugging | `delete-later-human-only` |
| `debug_invoice.php` | Invoice generation debugging | `delete-later-human-only` |
| `debug_photo_upload.php` | Photo upload debugging | `delete-later-human-only` |
| `table_debug.php` | Table rendering debug | `delete-later-human-only` |

---

## Test / diagnostic pages and scripts

| Path | Why quarantined | Recommended later action |
|------|-----------------|--------------------------|
| `test.php` | Exposes `phpinfo()` — high-risk if web-accessible | `delete-later-human-only` |
| `test_php.php` | Basic PHP smoke test | `delete-later-human-only` |
| `test_email.php` | Email test; historically embedded SMTP password in HTML (0A) | `delete-later-human-only` after credential rotation |
| `test_oauth_email.php` | OAuth email test harness | `delete-later-human-only` |
| `test_simple.php` | Ad-hoc test page | `delete-later-human-only` |
| `test_order_details.php` | Order details test | `delete-later-human-only` |
| `test_table.php` | Table UI test | `delete-later-human-only` |
| `test_display.php` | Display test | `delete-later-human-only` |
| `test_ajax.php` | AJAX test | `delete-later-human-only` |
| `test_ajax_json.php` | AJAX JSON test | `delete-later-human-only` |
| `test_ajax_photos.php` | Photo AJAX test | `delete-later-human-only` |
| `test_photo_display.php` | Photo display test | `delete-later-human-only` |
| `test_driver_assignment.php` | Driver assignment test | `delete-later-human-only` |
| `test_bread_distribution_performance.php` | Performance benchmark for bread distribution | `review` — may inform optimization later |
| `simple_performance_test.php` | Generic performance test; historically had inline DB credentials (0A) | `delete-later-human-only` |
| `test_db.php` | Database connectivity test | `delete-later-human-only` |
| `db_test.php` | Duplicate-style DB test | `delete-later-human-only` |
| `test.html` | Static HTML test artifact | `delete-later-human-only` |
| `test_xampp_default.ps1` | XAMPP path probe script | `review` |
| `route_tester.php` | Route testing utility | `review` |
| `run_sql_setup.php` | Web-runnable SQL setup for photos — bypasses normal migration flow | `delete-later-human-only` |
| `check_photo_db.php` | Photo DB inspection | `delete-later-human-only` |
| `find_photo_ids.php` | Photo ID lookup utility | `delete-later-human-only` |

---

## Orphan / alternate invoice scripts

Canonical candidate for production behavior is likely `generate_invoice.php` (uses shared config). Alternates are quarantined until a human picks one path.

| Path | Why quarantined | Recommended later action |
|------|-----------------|--------------------------|
| `generate_invoice.php` | Possible canonical invoice page | `keep` — confirm vs alternates before deleting others |
| `generate_invoice_simple.php` | Simplified variant | `review` |
| `generate_invoice_working.php` | Working-copy variant | `review` |
| `simple_invoice.php` | Minimal invoice experiment | `review` |
| `debug_invoice.php` | Invoice debug (also listed under debug) | `delete-later-human-only` |

---

## Experimental / alternate UI

| Path | Why quarantined | Recommended later action |
|------|-----------------|--------------------------|
| `products_new.php` | Alternate products management UI parallel to `products.php` | `review` — merge or retire after UX decision |

---

## PII / production data SQL (never commit as-is)

Listed in [CHECKPOINT_0A_REPOSITORY_CLASSIFICATION.md](CHECKPOINT_0A_REPOSITORY_CLASSIFICATION.md). Gitignored where noted in `bakery/.gitignore`.

| Path | Why quarantined | Recommended later action |
|------|-----------------|--------------------------|
| `bakerysf_schema.sql` | Full production-like data dump (customers, routes, GPS metadata) | `keep` local reference only; use `database/schema/` + fixtures for git |
| `update_customer_addresses.sql` | Real customer address UPDATEs | `keep` gitignored; do not commit |
| `update_customer_addresses_correct.sql` | Real customer address UPDATEs | `keep` gitignored; do not commit |
| `update_customer_coordinates.sql` | Real GPS coordinate UPDATEs | `keep` gitignored; do not commit |
| `update_missing_customer_coordinates.sql` | Real GPS coordinate UPDATEs | `keep` gitignored; do not commit |
| `update_final_customer_coordinates.sql` | Real GPS coordinate UPDATEs | `keep` gitignored; do not commit |
| `add_delivery_times.sql` | DDL plus UPDATEs referencing real customer names (0A) | `review` — sanitize before any commit |

---

## Credential / utility surfaces (not backups, but high-risk)

| Path | Why listed | Recommended later action |
|------|------------|--------------------------|
| `get_customers_no_address.php` | Disabled (503) — previously had hardcoded production DB credentials | `keep` disabled or remove in a dedicated security pass |

For rotation guidance see [CREDENTIAL_ROTATION_RUNBOOK.md](CREDENTIAL_ROTATION_RUNBOOK.md) — **link only**; do not duplicate secret locations into new docs.

---

## Runtime artifacts (gitignored — do not delete casually)

| Path | Why listed | Recommended later action |
|------|------------|--------------------------|
| `logs/error.log` | May contain customer/driver IDs and coordinates | `keep` gitignored; truncate locally as needed |
| `uploads/driver_photos/**` | Delivery photo binaries | `keep` gitignored; never commit |
| `.env` | Local credentials | `keep` gitignored |
| `oauth_tokens.json` (if present) | OAuth tokens | `keep` gitignored; rotate per runbook if exposed |

---

## How to extend this inventory

```powershell
cd C:\Users\918825809\CascadeProjects\windsurf-project\bakery
Get-ChildItem -Recurse -File |
  Where-Object {
    $_.Name -match 'backup|_backup|Copy|_fixed|_optimized|_working|debug|test_|table_debug|simple-debug|\.bak$'
  } |
  Select-Object -ExpandProperty FullName
```

**Do not** run destructive cleanups against matches. Add new findings to this file with category, rationale, and `DO NOT DELETE` status until human sign-off.

---

## Explicit policy

1. **No agent may delete** quarantined files during checkpoints 0A–0E.
2. Prefer **block public access** (auth + `.htaccess`) over deletion during modernization.
3. Characterization findings (Sunday encoding, zone join, etc.) remain documented bugs — see [CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md](CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md); quarantine status does not imply those are fixed.
