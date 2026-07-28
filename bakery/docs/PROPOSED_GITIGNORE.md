# Proposed `.gitignore` files (Checkpoint 0A)

**Status:** Proposed in Plan mode. Not yet written to disk as `.gitignore` because non-markdown file creation requires Agent mode approval.

After Agent mode is enabled, create these files exactly as below, then create branch `chore/checkpoint-0a-repo-safety` and commit **only** safety docs + ignore files (not the application tree).

---

## File 1: `bakery/.gitignore`

```gitignore
# =============================================================================
# Bakery Manager / Sour Flour OS — local .gitignore (Checkpoint 0A)
# Never commit secrets, production data, uploads, logs, or OAuth tokens.
# =============================================================================

# --- Environment and local overrides ---
.env
.env.*
!.env.example
*.local.php
local_config.php
includes/local_*.php

# --- Secrets and tokens (runtime) ---
oauth_tokens.json
**/oauth_tokens.json
*.pem
*.p12
*.key
credentials.json
client_secret*.json

# --- Logs ---
logs/
*.log

# --- Uploads and generated media (keep directory rules only) ---
uploads/driver_photos/**
!uploads/driver_photos/.htaccess
uploads/**
!uploads/driver_photos/
!uploads/driver_photos/.htaccess

# --- Production / PII-bearing SQL (do not commit as-is) ---
bakerysf_schema.sql
update_customer_addresses.sql
update_customer_addresses_correct.sql
update_customer_coordinates.sql
update_final_customer_coordinates.sql
update_missing_customer_coordinates.sql

# --- OS / IDE ---
.DS_Store
Thumbs.db
.idea/
.vscode/
*.swp
*~

# --- Test / temp artifacts ---
.phpunit.result.cache
coverage/
tmp/
temp/
*.tmp

# Note: Root repo .gitignore ignores vendor/. Until Composer is introduced,
# PHPMailer under bakery/vendor/ requires an explicit root negation to commit.
# See docs/CHECKPOINT_0A_REPOSITORY_CLASSIFICATION.md.
```

---

## File 2: Root `.gitignore` additions (append; do not remove existing rules)

Current root already ignores `vendor/`, `node_modules/`, `.env`, `*.env*`, `*.log`, `db_config.php`, IDE dirs, `*.local.php`.

**Proposed append:**

```gitignore

# --- Sour Flour / bakery safety (Checkpoint 0A) ---
!.env.example
!bakery/.env.example
bakery/logs/
bakery/**/*.log
bakery/oauth_tokens.json
bakery/**/oauth_tokens.json
bakery/uploads/driver_photos/**
!bakery/uploads/driver_photos/.htaccess
bakery/bakerysf_schema.sql
bakery/update_customer_addresses.sql
bakery/update_customer_addresses_correct.sql
bakery/update_customer_coordinates.sql
bakery/update_final_customer_coordinates.sql
bakery/update_missing_customer_coordinates.sql
```

**Deferred (Checkpoint 0B decision):** whether to add `!bakery/vendor/phpmailer/` so vendored PHPMailer can be committed despite root `vendor/`.

---

## Commit scope for Checkpoint 0A (when Agent mode allowed)

Stage **only**:

- `bakery/.gitignore`
- `bakery/docs/CHECKPOINT_0A_REPOSITORY_CLASSIFICATION.md`
- `bakery/docs/CREDENTIAL_ROTATION_RUNBOOK.md`
- `bakery/docs/PROPOSED_GITIGNORE.md` (optional; can delete after real `.gitignore` exists)
- Root `.gitignore` (safety append only)

**Do not stage:** `bakery/**/*.php`, uploads, logs, data SQL, vendor, or any file still containing hardcoded secrets.
