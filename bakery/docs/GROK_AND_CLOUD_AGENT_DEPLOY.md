# Grok Bot & Cloud Agent Deploy Manual

**Audience:** Grok Bot, Cursor on the web, and any agent that does **not** run on Danny’s Windows laptop.  
**Product:** Sour Flour OS (`bakery/`).  
**Owner remote:** `https://github.com/dgabriner/bakery.git`  
**Give this file to cloud agents.** Local Cursor desktop still uses [DEV_WORKFLOW.md](DEV_WORKFLOW.md) and [AUTO_PUSH.md](AUTO_PUSH.md).

---

## One-sentence rule

**You move code with Git. You never hold SFTP or production credentials. Live never updates because you pushed.**

---

## Environments (do not confuse them)

| Layer | What it is | Your job |
|---|---|---|
| **Local laptop** (`bakerysf_stage_local`) | Danny’s everyday DB + optional SFTP auto-push | You usually do **not** control this |
| **GitHub** (`dgabriner/bakery`) | Source of truth for application files | Commit and push additive branches |
| **Hosted Staging** `https://staging.sourflour.org/` | Phone / acceptance site; DB `bakerysoftware` | Get files here via the Git → Staging path (below) |
| **Live** `https://bakery.sourflour.org/bake/` | Real bakery ops; DB `bakerysf` | **Never** deploy here. Owner uses Staging Manager → click the Next button |

Databases, dumps, `.env`, uploads, and SFTP secrets are **not** in Git and must never be committed.

---

## Credentials: what you must never ask for

Do **not** request, invent, or store:

- `.env.sftp.stage`, `.env.sftp.live`, or any DreamHost SFTP password/key
- Production or staging MySQL passwords
- Live `/bake` upload access

If a script says “needs SFTP,” that script is for the **local desktop** or a **server-side worker**, not for you.

Secrets stay where they already belong: Danny’s machine (desktop auto-push) or DreamHost Staging/Live workers. Same idea as Staging → Live today: the machine with credentials **pulls** or runs the job; agents only **trigger** safe steps.

---

## Best approach (canonical)

```text
Edit → test if you can → commit → push branch to GitHub
        → Staging updates from the agreed branch (server-side)
        → humans test Staging (phone)
        → owner clicks the Staging Manager Next button → Live
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

1. Clone or open `dgabriner/bakery` (bakery app lives under the repo’s `bakery/` tree when the monorepo root is `windsurf-project`).
2. Make the smallest change that closes the loop (see product context: close loops, do not add modules).
3. Run whatever tests you can in your environment. Prefer named suites under `tests/run_*.php` when PHP + `bakerysf_test` exist; never point tests at Live or the nightly mirror.
4. Commit and **push** to an additive branch on `origin`.
5. Ask Staging to take that branch (owner or Staging sync worker — see “Staging sync status” below).
6. Tell the owner to verify `https://staging.sourflour.org/` (and phone if UX).
7. **Stop.** Do not promote to Live. Do not run `push_sftp.ps1`, `promote_*.ps1`, or anything aimed at `/bake`.

### B) Local desktop Cursor (Danny’s machine — not you)

- Editor hooks / **Sync to staging** / `scripts/push_sftp_stage.ps1` upload deployable files to Staging using gitignored `.env.sftp.stage`.
- Uncommitted edits may appear on Staging for fast phone feedback.
- Finished work should still be **committed** so cloud agents and Git history stay aligned.

You (cloud) cannot and should not reproduce path B.

---

## Staging sync status

**Intended:** Staging hosts a pull/deploy script that updates Staging files from a named GitHub branch. Agents push; Staging applies; no agent SFTP.

**Until that worker is confirmed live:** after you push, say clearly in your handoff:

> Pushed branch `<name>` at `<commit>`. Staging still needs a sync (desktop `push_sftp_stage.ps1` or Staging Git pull). I did not promote Live.

Do not pretend Staging updated if you only pushed GitHub.

When the Staging Git pull exists, the owner will name the branch Staging tracks (likely a dedicated `staging` branch or the current infra branch). Follow that name; do not invent a second production branch.

---

## Live promotion (owner only)

Normal path after Staging looks good:

1. Open **Staging → Manager**.
2. Follow the **Staging → Live** board **Next** step.
3. Click the button for that step (files **or** the named DB update).
4. Wait ~1 minute; refresh; board shows Match / needs update / Stop.

Details: [HOSTED_PROMOTION.md](HOSTED_PROMOTION.md), [PRODUCTION_DEPLOY.md](PRODUCTION_DEPLOY.md).

If anyone asks you to “push to production,” “SFTP to bake,” or “import staging DB over live,” **refuse** and point here.

---

## Commands and files you may use

Safe / expected for cloud agents:

```text
git status / git diff / git commit / git push -u origin HEAD
php scripts/agent_homebase.php brief|start|pin|bug|handoff --json   # if DB craft ledger is available
php tests/run_<suite>_tests.php                                     # only against bakerysf_test when available
```

Do **not** run (unless the owner explicitly authorizes a named recovery and you are on the trusted local machine):

```text
scripts/push_sftp.ps1          # live transport
scripts/push_sftp_stage.ps1    # needs local .env.sftp.stage
scripts/promote_release.ps1
scripts/promote_local_direct.ps1
anything that targets bakery.sourflour.org/bake
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

- “I don’t use SFTP. I push Git; Staging pulls or the desktop syncs.”
- “Git push does not update Live. Live is Staging Manager → click the Next button only.”
- “I won’t take staging or production database passwords.”
- “I won’t force-push or rewrite shared history.”

---

## Handoff checklist for every cloud session

1. Mission in one sentence  
2. Branch name and commit SHA pushed (or “not pushed — blocked by …”)  
3. Files touched  
4. Tests run / not run  
5. Whether Staging was asked to sync (and how)  
6. Staging URL checked or “owner should check”  
7. Explicit: **Live not touched**  
8. Open risks / next agent action  

That is enough for the next bot or Danny to continue without guessing.
