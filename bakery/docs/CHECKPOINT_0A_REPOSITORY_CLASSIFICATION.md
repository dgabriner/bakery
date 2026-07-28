# Checkpoint 0A — Repository and Safety Classification

**Date:** 2026-07-28  
**Git project root:** `C:\Users\918825809\CascadeProjects\windsurf-project`  
**Application path:** `bakery/` (untracked prior to this checkpoint)  
**Baseline commit (main):** `26ab450725a33b4fcb8747f9408adc55439d1e93` — “Initial commit”  
**Remote:** `origin` → `https://github.com/dgabriner/bakery.git`  
**Branch created for this work:** `chore/checkpoint-0a-repo-safety`  
**Ignore files:** Applied on disk (`bakery/.gitignore` + root `.gitignore` append)

**Evidence labels:** Verified in git | Verified on disk | Inferred

This report intentionally **does not** contain secret values, customer addresses, GPS coordinates, or dump excerpts.

---

## 1. Git root confirmation

| Fact | Value | Label |
|------|-------|-------|
| Correct git root for commits | `windsurf-project` (parent monorepo) | Verified in git |
| Nested `bakery/.git` | Not present | Verified on disk |
| Files currently tracked under `bakery/` | **0** | Verified in git |
| Parent working tree | Many untracked dirs: `bakery/`, `todo/`, `trade/`, `Amaya/`, `life_todo/`, `photo-taker/`, etc. | Verified in git |
| Existing root `.gitignore` | Present; ignores `vendor/`, `node_modules/`, `.env`, `*.env*`, `*.log`, `db_config.php`, IDE dirs, `*.local.php` | Verified on disk |
| Existing `bakery/.gitignore` | Was absent before this checkpoint | Verified on disk |

**Implication:** Adding `bakery/` requires an explicit, filtered add. A broad `git add bakery` would be unsafe until ignores and sanitization rules are in place.

---

## 2. SQL dumps — schema vs production data

### `bakerysf_schema.sql` (~64 KB)

| Aspect | Finding | Label |
|--------|---------|-------|
| `CREATE TABLE` count | 16 | Verified on disk |
| `INSERT INTO` count | 13 statement groups | Verified on disk |
| Tables with INSERT data | `customers`, `dough_types`, `drivers`, `driver_history`, `driver_photos`, `formula_ingredients`, `ingredients`, `leads`, `lead_contacts`, `products`, `standing_orders`, `standing_routes`, `zones` | Verified on disk |
| Classification | **Production-like data dump**, not schema-only | Verified on disk |

**Verdict:** **Never commit as-is.** Contains real customer names/addresses, standing orders, routes, GPS-related photo metadata, and operational history. Checkpoint 0B must build a **sanitized schema + fictional fixtures** instead.

### Other SQL files

| File | Nature | Commit guidance |
|------|--------|-----------------|
| `create_daily_order_assignments_table.sql` | DDL | Safe to commit after review |
| `setup_photo_functionality.sql` | DDL | Safe to commit after review |
| `standing_orders_performance_optimization.sql` | DDL / indexes / view | Safe to commit after review |
| `product_lines_setup.sql` | DDL + seed product-line names | Safe (no customer PII) |
| `add_coordinates_to_customers.sql` | DDL (lat/lng columns) | Safe DDL |
| `add_default_quantity_columns.sql` | DDL + default UPDATEs | Safe (no customer PII) |
| `assign_dough_types_to_product_lines.sql` | UPDATE by dough type name | Safe (no customer PII) |
| `add_delivery_times.sql` | DDL + UPDATE by **real customer names** | **Sanitize or do not commit** |
| `update_customer_addresses.sql` | Data UPDATE | **Never commit** (PII) |
| `update_customer_addresses_correct.sql` | Data UPDATE | **Never commit** (PII) |
| `update_customer_coordinates.sql` | Data UPDATE | **Never commit** (PII / GPS) |
| `update_final_customer_coordinates.sql` | Data UPDATE | **Never commit** (PII / GPS) |
| `update_missing_customer_coordinates.sql` | Data UPDATE | **Never commit** (PII / GPS) |

`bakery/.gitignore` excludes the schema dump and the `update_customer_*.sql` family.

---

## 3. Credential and secret locations (values omitted)

Hardcoded or fallback secrets were found in application files. **Do not commit these files until secrets are removed** (Checkpoint 0B+). Rotate credentials using the runbook; do not rotate from the agent.

| Area | File(s) | Kind (name only) |
|------|---------|------------------|
| DB fallback | `includes/config.php` | `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` |
| SMTP | `includes/email_config.php` | `SMTP_PASSWORD` (and related) |
| OAuth | `includes/gmail_oauth.php` | `CLIENT_ID`, `CLIENT_SECRET` |
| Maps | `includes/google_maps_config.php` | `GOOGLE_MAPS_API_KEY` |
| Inline DB | `complete_delivery.php`, `debug_order_details.php`, `simple-debug.php`, `simple_performance_test.php`, `get_customers_no_address.php` | Hardcoded PDO password / DSN |
| Email debug text | `test_email.php` | Embeds SMTP password in HTML troubleshooting text |

Runtime token file `oauth_tokens.json`: **not present** on disk at inspection time (expected after OAuth). Must remain gitignored.

---

## 4. Customer data, photos, logs

| Asset | Finding | Commit? |
|-------|---------|---------|
| Delivery photos | 8 JPG under `uploads/driver_photos/2025/06/` named with driver/customer IDs | **Never** |
| Upload `.htaccess` | Present; safe to keep in git | Yes (rule file only) |
| `logs/error.log` | ~21 KB; contains local paths, customer_id, driver_id, lat/lng | **Never** |
| Schema INSERTs | Real SF wholesale customers, orders, routes | **Never as-is** |
| Address/coordinate SQL | Real addresses and coordinates | **Never** |

---

## 5. Classification summary

### A. Safe to commit **now** (Checkpoint 0A artifacts only)

- `bakery/.gitignore`
- `bakery/docs/CHECKPOINT_0A_REPOSITORY_CLASSIFICATION.md`
- `bakery/docs/CREDENTIAL_ROTATION_RUNBOOK.md`
- Root `.gitignore` adjustments that improve safety (see §7)

### B. Safe to commit **later**, after secret removal / sanitization (not this commit)

- Application PHP/CSS/JS **after** hardcoded secrets removed
- Schema-only / DDL migrations listed as safe above
- `uploads/driver_photos/.htaccess`
- Documentation that does not embed secrets (`README` rewrite, architecture notes)
- Vendored PHPMailer **only if** root `vendor/` ignore is negated or Composer install is documented

### C. Never commit

- `.env`, OAuth token JSON, private keys
- `logs/**`, `*.log`
- `uploads/driver_photos/**` media files
- `bakerysf_schema.sql` (data dump)
- `update_customer_*.sql` and similar PII data scripts
- Any export containing customer, payment, or delivery photo content

### D. Requires sanitization before commit

- All PHP files that currently embed secret fallbacks (replace with env/external config; no production fallbacks)
- `add_delivery_times.sql` (strip or fictionalize named customer UPDATEs)
- Any future schema dump (structure only, or fictional fixtures only)
- Engineering docs that may have copied secret values historically (scan before commit)

### E. Existing **tracked** secrets outside `bakery/` (monorepo note)

Parent repo already tracks 13 files, including `php-project/config/database.php` (local empty-password pattern) and `todo.db` / `gtd_data.json`. These are **outside** Checkpoint 0A bakery scope but are residual monorepo risk. No bakery secrets are currently tracked (bakery was fully untracked).

---

## 6. High-risk untracked application surfaces (do not `git add` yet)

Until Checkpoint 0B removes production credential fallbacks:

- Entire `bakery/includes/*config*.php` and `gmail_oauth.php`
- `complete_delivery.php` and debug/test scripts with inline DB credentials
- Diagnostic pages (`test.php` phpinfo, `run_sql_setup.php`, `debug*.php`, etc.)
- Data-bearing SQL listed above
- Upload binaries and logs

---

## 7. Proposed `.gitignore` changes

### New: `bakery/.gitignore`

Created in this checkpoint. Excludes env files, tokens, logs, upload media, and PII-bearing SQL dumps/scripts. Allows `.env.example` and upload `.htaccess`.

### Proposed root `.gitignore` updates

1. Allow example env files: `!.env.example` and `!bakery/.env.example`
2. Explicit bakery ignores (defense in depth): `bakery/logs/`, `bakery/uploads/driver_photos/**` with `.htaccess` exception
3. Optional later: `!bakery/vendor/phpmailer/` so PHPMailer can be committed despite root `vendor/` — **deferred** until a deliberate decision in 0B (Composer vs vendored tree)

**Not done in 0A:** Broad `git add bakery/`. Only safety docs + ignore files are staged.

---

## 8. Credential rotation

Production credentials must be rotated by the operator, not by the agent. See [CREDENTIAL_ROTATION_RUNBOOK.md](CREDENTIAL_ROTATION_RUNBOOK.md).

---

## 9. Checkpoint 0A stop criteria

| Criterion | Status |
|-----------|--------|
| Git root confirmed | Done |
| Sensitive files identified | Done |
| Safe `.gitignore` drafted | Done |
| Classification report written | Done |
| No production credentials altered | Done |
| No broad `git add bakery` | Done |
| Implementation branch created | Done (with this checkpoint commit) |
| Functional application code unchanged | Done |

**Next (only after human review):** Checkpoint 0B — local-only environment with nonproduction database and no production credential fallbacks.
