# Grok Bot & Cloud Agent Deploy Manual

**Audience:** Grok Bot, Cursor on the web, and any agent that does **not** run on Danny’s Windows laptop.  
**Product:** Sour Flour OS (`bakery/`).  
**Owner remote:** `https://github.com/dgabriner/bakery.git`  
**Canonical branch:** `main`  
**Retired:** `https://github.com/SheepMiner/Bakery.git` — backup / old Cloud sandbox only. Do not clone it, open PRs against it, or merge its default (`chore/checkpoint-0a-repo-safety`).  
**Give this file to cloud agents.** Local Cursor desktop still uses [DEV_WORKFLOW.md](DEV_WORKFLOW.md) and [AUTO_PUSH.md](AUTO_PUSH.md).

---

## One-sentence rule

**Git records the work. Staging SFTP secrets in this cloud environment update Staging. “Stage and live” queues the hosted Live workers — it is not a Live SFTP push.**

---

## Environments (do not confuse them)

| Layer | What it is | Your job |
|---|---|---|
| **Local laptop** (`bakerysf_stage_local`) | Danny’s everyday DB + optional SFTP auto-push | You usually do **not** control this |
| **GitHub** (`dgabriner/bakery`, branch `main`) | Source of truth for application files | Commit and push additive branches to **this** repo |
| **Hosted Staging** `https://staging.sourflour.org/` | Phone / acceptance site; DB `bakerysoftware` | Get files here via the Git → Staging path (below) |
| **Live** `https://bakery.sourflour.org/bake/` | Real bakery ops; DB `bakerysf` | When the owner says **Stage and live**, queue hosted workers (`--queue-live`). Do **not** SFTP to `/bake`. |

Databases, dumps, `.env`, uploads, and SFTP secrets are **not** in Git and must never be committed.

---

## Credentials

This Cursor cloud environment already injects **staging** SFTP secrets (`SFTP_HOST`, `SFTP_USER`, `SFTP_PASSWORD`, `SFTP_REMOTE_ROOT`, `SFTP_TARGET`). Check `CLOUD_AGENT_INJECTED_SECRET_NAMES`. Do not print values. Do not ask the owner to paste passwords into chat.

Do **not** request or invent:

- Live `/bake` SFTP (`.env.sftp.live`)
- Production or staging MySQL passwords
- Whole-database dumps

`scripts/sftp_upload.py` and `scripts/cloud_agent_stage.py` refuse a Live remote root. Live apply is the hosted worker after Staging approval manifests are written.

### Live ops staff login (owner-authorized)

For **read-only** live checks (Login History, Bakery Manager routes, Daily Run), use the Cloud Agent environment secrets and skill:

- Skill: [`.cursor/skills/live-ops-login/SKILL.md`](../.cursor/skills/live-ops-login/SKILL.md)
- Secrets: `BAKERY_LIVE_AGENT_CODE` (Cursor Agent / manager — default), `BAKERY_LIVE_ADMIN_CODE` (Danny / admin — Login History and admin screens)
- Live URL: `https://bakery.sourflour.org/bake/login.php`

Do **not** put the digit codes in Git, PR bodies, or new docs. Staging DB is not live delivery progress.

---

## Best approach (canonical)

```text
Edit → test if you can → commit → push branch to GitHub
        → python3 -m pip install --user paramiko
        → python3 scripts/cloud_agent_stage.py --files … --migration database/schema/NNN_slug.sql --migrate-hosted --smoke
        → when the owner says "Stage and live":
             python3 scripts/cloud_agent_stage.py --queue-live --migration-id NNN_slug.sql
             wait ~1 min; curl https://bakery.sourflour.org/bake/migration_status.php
             python3 scripts/cloud_agent_stage.py --queue-live --files-live
             wait ~1 min; curl https://bakery.sourflour.org/bake/deploy_status.php
```

### Why this is safe

1. **Git does not deploy Live.** Pushing cannot change `bakery.sourflour.org/bake` by itself.
2. **Staging → Live** is a separate hosted board ([HOSTED_PROMOTION.md](HOSTED_PROMOTION.md)): click the Next button to send files or the named DB migration. No laptop required.
3. **Whole staging DB copies never overwrite Live.** “Database update” means an approved additive migration, not importing Staging data over production.

### What “using Git properly” means for you

- Prefer **additive** branches (`codex/…`, `cursor/…`, `grok/…`). Do not rewrite history.
- **Never** force-push to shared branches. **Never** update Live by pushing `main` or any default branch.
- Commit **application source only** (PHP, CSS, JS, schema SQL under `database/schema/`, docs as needed). New schema files must use the next unused `NNN` from `php scripts/next_schema_migration.php --name=slug`. Do not reuse 062 or any other taken prefix.
- If you add a **new root-level** `.php` page (next to `login.php`), also add its filename to `Get-BakeryDeployRootFiles` in `scripts/deploy_manifest.ps1`. Staging Sync uses that whitelist; a missing name produces a Staging 404 while other files still “sync fine.”
- New pages often need **new includes** under `includes/`. A Staging **500** with a page that works locally usually means the page uploaded but a `require_once` target did not. Confirm dependencies are on Staging, not only the root PHP file.
- Secret-scan before commit: no `.env`, dumps, `storage/dumps/`, deploy state, credentials.
- Leave a clear commit message and a short handoff of files touched.
- Do not treat “GitHub has my commit” as “Staging or Live is updated” until Staging actually shows the change.

---

## Two modes of work

### A) Cloud agent (you — Grok / Cursor web)

1. Clone or open **`dgabriner/bakery` on `main`** (bakery app lives under the repo’s `bakery/` tree). Do not use `SheepMiner/Bakery`.
2. Make the smallest change that closes the loop (see product context: close loops, do not add modules).
3. Run the tests. Cloud VMs boot from `.cursor/environment.json` (repo root), which runs `bakery/scripts/cloud_agent_install.sh`: PHP 8.3 + MariaDB, a loopback `bakerysf_test` built from `database/schema` + `database/fixtures`, and a local-only `bakery/.env`. Then use the Linux gate — `bash scripts/run_test_gate.sh --files=a.php,b.php` (work-map suites) or no flags for lint + reset + every suite. Eight suites need production-snapshot data or gitignored quarantine files and are skipped on fixture databases (listed in the script as `DESKTOP_ONLY_SUITES`); say so in the handoff. Never point tests at Live or the nightly mirror.
4. Commit and **push** to an additive branch on `origin`.
5. **Stage yourself** with `scripts/cloud_agent_stage.py` (injected staging SFTP). New `050+` SQL must be hosted-gate portable: `INSERT IGNORE`, `CREATE TABLE IF NOT EXISTS`, additive `ALTER TABLE … ADD`. `ON DUPLICATE KEY UPDATE` fails the hosted gate.
6. If the owner says **Stage and live**, that is Live authorization. Queue migration first, then files (`--queue-live`). Poll `migration_status.php` / `deploy_status.php`. Do not run `push_sftp.ps1` or upload to `/bake`.
7. If staging SFTP secrets are missing, say so and stop. Do not invent credentials.

### B) Local desktop Cursor (Danny’s machine — not you)

- Editor hooks / **Sync to staging** / `scripts/push_sftp_stage.ps1` upload deployable files to Staging using gitignored `.env.sftp.stage`.
- Uncommitted edits may appear on Staging for fast phone feedback.
- Finished work should still be **committed** so cloud agents and Git history stay aligned.

Path B is the laptop equivalent of the same Staging SFTP. Cloud agents use path A + `cloud_agent_stage.py` instead of PowerShell.

---

## Staging sync status

GitHub push alone does **not** update Staging. In this cloud environment, Staging SFTP **is** available. After you upload, say:

> Staged `<files>` at `<time>`. Hosted bakerysoftware migration `<id>` applied. Smoke: Staging login 200.

When a Staging Git pull exists, it tracks **`main` on `dgabriner/bakery`**. Do not invent a second production branch.

### CI vs Staging vs Live (Mission 54)

- GitHub Actions workflow `.github/workflows/test-gate.yml` runs the Linux gate on PRs (mapped suites via `--changed-since=origin/main --no-reset --report=json`) and the full gate on `main`.
- CI green is **required before Staging sync**. CI ≠ Staging ≠ Live. The workflow holds **no** SFTP secrets and never deploys.
- `USE_PROD_DB=true` or a non-loopback `DB_HOST` still fail closed via `includes/test_target_guard.php`.

---

## Live promotion

“Stage and live” from the owner means: queue the hosted workers, do not SFTP Live.

1. Publish the numbered SQL to the Staging vault and `--migrate-hosted` (bakerysoftware only).
2. `--queue-live --migration-id NNN_slug.sql` then wait for Live `migration_status.php` to name that id and `succeeded`.
3. `--queue-live --files-live` then wait for `deploy_status.php` `succeeded` with a new `release_id`.
4. Confirm the new page is no longer a Live 404 (login 302 is success).

Staging Manager **Next** is the same queue if a human is at the board. `scripts/queue_hosted_live.php` is the SSH/CLI form.

Refuse only: Live SFTP to `/bake`, `promote_local_direct.ps1`, or importing a staging/local database over `bakerysf`.

---

## Commands and files you may use

Safe / expected for cloud agents:

```text
git status / git diff / git commit / git push -u origin HEAD
python3 -m pip install --user paramiko
python3 scripts/cloud_agent_stage.py --check-target
python3 scripts/cloud_agent_stage.py --files FILE… --migration database/schema/NNN_slug.sql --migrate-hosted --smoke
python3 scripts/cloud_agent_stage.py --queue-live --migration-id NNN_slug.sql
python3 scripts/cloud_agent_stage.py --queue-live --files-live
php scripts/agent_homebase.php brief|start|pin|bug|handoff --json
php tests/run_<suite>_tests.php
bash scripts/run_test_gate.sh [--files=a.php,b.php | --suites=run_x_tests | --changed-since=origin/main]
bash scripts/cloud_agent_install.sh          # re-provision PHP/MariaDB/bakerysf_test if the VM lacks them
```

Do **not** run:

```text
scripts/push_sftp.ps1              # Live SFTP transport
scripts/promote_local_direct.ps1   # emergency laptop bypass
anything that targets bakery.sourflour.org/bake over SFTP
whole-database import onto bakerysf
```

---

## Non-negotiables (from Homebase)

- Close loops; do not add modules or top-level pages unless asked.
- Staging auto-push / sync must **never** target `bakery.sourflour.org/bake`.
- Tests target `bakerysf_test` only when a test DB exists.
- Full DB copies: production → local/staging only — never the reverse wholesale.
- i18n: both `lang/en.php` and `lang/es.php` when you add user-facing strings.
- Chat is steam; pin lasting decisions and hand off with the eight §10 fields when using Agent Homebase.

Product manual: `BAKERY_PRODUCT_CONTEXT.md`.  
Craft: [AGENT_DEVELOPMENT_MANUAL.md](AGENT_DEVELOPMENT_MANUAL.md).  
Data/Git plan: [DATA_ENVIRONMENT_STABILIZATION_PLAN.md](DATA_ENVIRONMENT_STABILIZATION_PLAN.md).

---

## Quick refusal phrases (copy these)

- “I will not SFTP Live `/bake`. I stage with injected staging secrets, then queue the hosted Live workers.”
- “Git push does not update Staging or Live by itself.”
- “I won’t take or print staging or production database passwords.”
- “I won’t force-push or rewrite shared history.”
- “I won’t import a staging/local database over bakerysf.”

---

## Handoff checklist for every cloud session

1. Mission in one sentence  
2. Branch name and commit SHA pushed (or “not pushed — blocked by …”)  
3. Files touched  
4. Tests run / not run  
5. Staging: files uploaded / hosted migration id / smoke result  
6. Live: queued or not; `migration_status.php` / `deploy_status.php` ids  
7. Confirm Live was **not** reached by SFTP  
8. Open risks / next agent action  

That is enough for the next bot or Danny to continue without guessing.
