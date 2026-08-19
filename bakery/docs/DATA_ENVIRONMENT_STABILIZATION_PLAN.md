# Data Environment and Git Stabilization Plan

**Owner intent recorded:** 2026-08-18  
**Execution owner:** an agent, with the bakery owner approving only the named production gates  
**Current status:** Local data, backup, restore-drill, staging, and release-candidate infrastructure is implemented on `codex/infrastructure-stabilization-20260818`. Production remains unchanged and live promotion execution remains locked.

This is the authoritative plan for separating development, staging, production,
backups, and Git without losing the current working tree or overwriting bakery
operations data.

## Outcome

The finished system has four durable data layers and one disposable test layer:

| Layer | Stable name | Data source | May be mutated? | Purpose |
|---|---|---|---|---|
| Nightly local mirror | `bakerysf_local` initially | Nightly production dump | No normal app/test writes | Exact recent production reference |
| Local staging | `bakerysf_stage_local` | Clone/import of the newest verified production dump | Yes | Everyday development |
| Disposable local test | `bakerysf_test` | Fresh clone/import of the same production dump | Yes; reset freely | Automated regression tests |
| DreamHost staging | New unique DreamHost DB name | Verified copy of live production | Yes | Phone and hosted acceptance testing |
| Live production | `bakerysf` | Live bakery activity | Only normal application writes and approved migrations | Real operations |

There is only **one source of test data**: a verified production snapshot. Local
staging and disposable test are separate databases so destructive tests cannot
damage the untouched mirror. They are not separate fixture/data universes.

No SQL dump, customer data, credentials, or database state is stored in GitHub.

DreamHost implementation notes:

- DreamHost databases must first be created in the panel and cannot be renamed;
  use a final, unique staging name from the start:
  <https://help.dreamhost.com/hc/en-us/articles/221691727-Creating-a-MySQL-database>
- DreamHost recommends a separate database user per database. Staging and live
  will not share a user:
  <https://help.dreamhost.com/hc/en-us/articles/360060957212-Adding-and-deleting-a-database-user>
- DreamHost supports locked cron jobs for server-side workers:
  <https://help.dreamhost.com/hc/en-us/articles/215088668-Create-a-cron-job>
- DreamHost daily restore points are short-term and not guaranteed, so the local
  and off-machine backup layers remain required:
  <https://help.dreamhost.com/hc/en-us/articles/215100557-Restore-a-database-in-the-panel>

## Non-negotiable safety rules

1. Production remains available during this migration. No phase begins by
   deleting, resetting, or replacing live data.
2. Data replication is full-copy **from live to staging/local only**.
3. Staging never replaces live operational/customer/order data wholesale.
   Staging-to-live promotion carries:
   - a reviewed code artifact;
   - forward schema migrations;
   - explicitly authored and reviewed reference-data patches, when required.
4. Every production promotion starts with a verified production dump and ends
   with health checks and a release record.
5. Editor hooks may auto-deploy to DreamHost staging after Phase 4. They must
   never target live production.
6. Git operations are additive during stabilization: no reset, force-push,
   history rewrite, clean, checkout-overwrite, or broad deletion.
7. The untouched nightly mirror and weekly backups are never test targets.
8. Production-derived data contains PII. Dumps stay gitignored, access-limited,
   encrypted/off-machine where practical, and never enter GitHub Actions.

## Important meaning of “database update”

A production database update means applying the schema and narrowly reviewed
data migrations belonging to a tested release. It does **not** mean importing
the staging database over production. A whole staging import would erase orders,
deliveries, invoices, users, and other activity created on live after the staging
copy was taken.

The Staging **Promote** button and Live **Pull approved release** button will be
two views of the same release job, not two independent copy mechanisms.

## Phase 0 — freeze and preserve the current state

Goal: make every following step recoverable before changing Git or databases.

- [x] Keep local runtime on `USE_PROD_DB=false`.
- [x] Disable and stop the live SFTP auto-push watcher.
- [x] Remove/disable Cursor hook access to live credentials. Phase 4 hooks
      require `.env.sftp.stage` and never load `.env.sftp` / `.env.sftp.live`.
- [x] Capture a timestamped filesystem archive of the bakery source, excluding
      runtime PII dumps only when they are archived separately.
- [x] Create a new verified production SQL backup and a separate local SQL dump.
      The owner explicitly authorized the production read on 2026-08-18.
- [x] Create a separate local `bakerysf_local` SQL dump without contacting
      production.
- [x] Write SHA-256 checksums and a manifest for the completed local artifacts.
- [x] Create a `git bundle` of all currently committed history.
- [x] Record current branch, remotes, HEAD, changed tracked files, untracked
      files, database names, migration ledgers, and latest deploy record.
- [x] Prove the source archive, local SQL dump, and Git bundle are readable.

Phase 0 local recovery evidence is stored outside Git under
`storage/dumps/recovery_20260818_165008/`. Its manifest is
`MANIFEST.json`; the manifest SHA-256 is
`6d3058cca8d77b72352afabef023afa632aca7659eb2ef12c0841bbff2929b99`.
The source archive contains no runtime `.env`, deployment state, dumps, logs,
or uploads. The Git bundle reports complete history.

The authorized production backup is stored outside Git as
`storage/dumps/bakerysf_prod_backup_20260818_235745_phase0_git_recovery_authorized.sql`
(1,965,168 bytes; SHA-256
`ca2c19d8889c039477e9c4abe2dcac6c8011642d4f08b01c0c81783575665877`).
It contains the expected core tables, migration ledger, and completed-dump
marker. Only aggregate row counts were read during verification; no production
write occurred.

Exit gate: source archive, Git bundle, and database dumps exist and their hashes
verify. No production write has occurred.

Rollback: none needed; Phase 0 is additive.

## Phase 1 — make Git a safety net, not a deployment switch

Git tracks application files only. It does not connect to or change MariaDB and
it does not deploy merely because a commit exists.

Agent procedure:

1. Secret-scan changed and untracked files. Confirm `.env`, production dumps,
   SFTP credentials, uploads, and logs remain ignored.
2. Keep the existing monorepo root during recovery. Stage only explicit
   `bakery/...` paths so sibling projects cannot enter the bakery repository.
3. Create a new local recovery branch named with the `codex/` prefix.
4. Make a local “current recovered workspace” commit. Do not push yet.
5. Run source inventory, PHP lint, and non-database checks. Record failures
   without altering history.
6. Push the new branch to the owner’s `origin` as a **new remote branch**. Never
   force-push and never update `main` in this phase.
7. Verify the GitHub file tree and secret scan. Then set the owner’s repository
   as the branch upstream instead of the SheepMiner fork.
8. Add branch protection for the eventual production branch after the test gate
   exists.

Exit gate: the complete intended application source is recoverable from both a
local archive and a new GitHub branch; production and `main` are untouched.

Rollback: delete only the newly created remote branch if the owner requests it;
the local archive, bundle, existing branch, and databases remain intact.

Phase 1 recovery evidence (2026-08-18): the explicit source allow-list staged
587 bakery paths, including the previously ignored 67-file PHPMailer runtime
dependency. It excluded environment files, production dumps, logs, deploy
runtime state, driver uploads, temporary repair files, and sibling projects.
Secret-pattern and local-secret-value scans found no matches. PHP lint passed
for 297 of 298 staged PHP files; the known pre-existing failure is
`drivers.php:276` (unexpected identifier `c`). The recovery commit is
`060464e8bb5cc931a18e2513f56ce5ed625456e5` on
`codex/data-stabilization-recovery-20260818`, and the owner remote verifies the
same commit at
`https://github.com/dgabriner/bakery/tree/codex/data-stabilization-recovery-20260818`.
No force-push, `main` update, deployment, staging write, or production write
occurred.

## Phase 2 — production-derived local development and testing

Replace the current fixture split with a snapshot/clone workflow.

### Nightly snapshot job

1. Connect to production with a dedicated read/backup credential if DreamHost
   privileges allow it.
2. Create `storage/dumps/nightly/live_YYYYMMDD_HHMMSS.sql.gz` using a
   transaction-consistent dump.
3. Verify nonzero size, gzip integrity, expected core tables, row-count ranges,
   and SHA-256 checksum.
4. Import into a temporary local database and verify again.
5. Only after verification, refresh the stable read-only mirror
   `bakerysf_local`.
6. Record the source timestamp, schema ledger, counts, hash, and result in a
   JSONL audit log.
7. If any step fails, retain the prior mirror and alert; never replace it with a
   partial import.

Run nightly with Windows Task Scheduler when the computer is available, plus a
catch-up check at login. The current startup script must not destroy the mirror
before a new dump/import has passed verification.

### Local staging refresh

`bakerysf_stage_local` is recreated from the newest verified nightly dump, then
the candidate branch migrations are applied. Before refresh, take a small
development checkpoint dump so unfinished local investigation can be restored.
The local website points here for normal development.

### Disposable test refresh

`bakerysf_test` is recreated from the same verified nightly dump, then candidate
migrations are applied. All database-backed tests must force the actual PDO
target to exactly `bakerysf_test`; `_local` is no longer sufficient. Existing
demo fixtures are retired after equivalent production-derived regression cases
are proven.

GitHub-hosted Actions receive no production dump. Initially they run lint and
static/source-contract checks only. Database regression tests run through the
trusted local agent gate. A self-hosted runner can be considered later, but is
not required for stabilization.

Phase 2 implementation evidence (2026-08-19 UTC):

- Verified snapshot: `storage/dumps/nightly/live_20260819_003445_phase2_baseline.sql.gz`
  (SHA-256 `25a702bda30a820d4259c26a2a29f1b39acb40719b3eccef0e6547a6320f10e3`).
- The same snapshot rebuilt `bakerysf_local`, `bakerysf_stage_local`, and
  `bakerysf_test`; each matched the captured core counts (107 customers, 54
  products, 4,441 standing orders, 6 drivers, 105 default quantities, 11 users,
  878 daily orders). Pre-refresh checkpoints were retained.
- Test resets now use the newest verified snapshot only. The exact target guard
  rejects every database except `bakerysf_test`; mirror/staging-local writes are
  separately allow-listed for refresh tooling.
- The full local lint, snapshot, authentication, characterization, driver,
  golden-day, integrity, SF Baker, and operational gate completed with exit code
  0. Informational findings remain documented; no production behavior was
  changed to hide them.

Exit gate: **passed locally**. The mirror cannot be mutated by app/tests; local
staging and test rebuild from the same verified snapshot; full regression gate
refuses every other DB.

Rollback: point local `.env` back to the preserved `bakerysf_local` only for
read-only inspection, or restore the pre-refresh local staging dump.

## Phase 3 — create DreamHost staging

DreamHost resources are created once through the panel, then managed by agents:

- a staging hostname/subdomain and separate document root;
- a new uniquely named staging database;
- a database user used only by staging;
- separate SFTP/shell credentials or a strictly separate remote root;
- a staging `.env` that cannot be mistaken for production.

DreamHost recommends a different database user for each database. The staging
app must display a strong STAGING banner and disable real-world side effects:

- mail logs instead of sending;
- payment integrations use sandbox/off;
- no production cron or synthetic tick;
- no customer/driver notifications;
- no production SFTP target;
- administrator access reviewed after each refresh.

The refresh path is one-way: live production dump → staging backup → staging
import → staging-only migrations → health check. Staging refresh never writes
to live.

Phase 3 planning evidence (2026-08-18, no hosted mutation):

- Planning branch: `codex/phase3-dreamhost-staging-plan-20260818` from Phase 2
  `db0ecd1`. Detail: `docs/PHASE3_DREAMHOST_STAGING_PLAN.md`.
- Production remains `https://bakery.sourflour.org/bake/` → MySQL `bakerysf` on
  `mysql.sourflour.org`. Live auto-push stays disabled.
- Owner named hosted staging: `https://staging.sourflour.org/` on Shared
  Unlimited `iad1-shared-b7-08`, SFTP host `iad1-shared-b7-08.dreamhost.com`,
  SFTP user `bakeryOS`, assumed remote root `staging.sourflour.org`. Do not
  use production user `dh_dp755h` or path `bakery.sourflour.org/bake`.
- Owner offered unused DreamHost database `bakerysoftware` as a candidate that
  should be able to serve the same role as `bakerysf`. DreamHost cannot rename
  databases, so that name would be permanent if adopted. No connection, import,
  or deploy was performed.
- Staging MySQL user must be associated only with the staging database. A user
  that can also access `bakerysf` is not acceptable, even if the unused database
  itself is the right name. `lavictoriasf` exclusivity is still an open owner
  question.
- Templates (no secrets): `storage/deploy/STAGING_ENV.example`,
  `.env.sftp.stage.example`. Local gitignored `.env.sftp.stage` holds the
  bakeryOS SFTP target for later Phase 4; auto-push stays disabled.
- Still blocked on owner Gate 2 (use hosted staging: place `.env` / upload to
  `bakeryOS` only) and later Gate 3 (first staging refresh from live).

Phase 3 execution evidence (2026-08-18, production untouched):

- Owner Gate 2: use `https://staging.sourflour.org/` / SFTP user `bakeryOS`.
- Owner Gate 3: first refresh of `bakerysoftware` from the verified Phase 2
  snapshot `storage/dumps/nightly/live_20260819_003445_phase2_baseline.sql.gz`.
- Uploaded 383 deployable files plus 26 operational root pages and a staging
  `.env` (`APP_ENV=staging`, `MAIL_DRIVER=log`, `DB_NAME=bakerysoftware`) to
  `bakeryOS` / `staging.sourflour.org` only. Live `.env.sftp` was not used.
- Login at `https://staging.sourflour.org/login.php` shows the Spanish STAGING
  banner and `bakerysoftware @ mysql.sourflour.org`.
- Staging import counts matched the snapshot: 107 customers, 54 products,
  4,441 standing orders, 6 drivers, 11 users, 878 daily orders.
- Phone acceptance on that URL remains the Phase 3 exit check.

Exit gate: phone can complete a representative acceptance flow on the staging
URL; production counts and files remain unchanged.

Rollback: restore the pre-refresh staging dump or remove only the new staging
resources.

## Phase 4 — staging auto-deploy and immutable releases

Repoint the existing automatic file workflow to staging only. Use distinct files
such as `.env.sftp.stage` and `.env.sftp.live`; scripts must refuse ambiguous or
mismatched remote roots.

A staging deployment batch performs:

1. debounce local edits;
2. identify changed deployable files;
3. lint changed PHP;
4. build a release manifest with commit/working-tree identity and file hashes;
5. snapshot staging DB if schema will change;
6. upload to staging;
7. run the canonical migration runner in staging mode;
8. run health/smoke checks;
9. record success/failure and exact files/migrations.

Uncommitted edits may auto-deploy to staging for fast phone feedback, but only a
committed, tested manifest can become a production release candidate.

Phase 4 evidence (2026-08-18, production auto-push remains unreachable):

- Auto-push queue, worker, Cursor hook, and local UI Sync call
  `scripts/push_sftp_stage.ps1` only. They require `.env.sftp.stage` and never
  load `.env.sftp` / `.env.sftp.live`.
- Live `scripts/push_sftp.ps1` prefers `.env.sftp.live`, requires
  `SFTP_TARGET=dreamhost-live`, and refuses `bakeryOS` plus
  `staging.sourflour.org`. Auto-push never calls it.
- Staging incremental pushes lint PHP, write
  `storage/deploy/stage/releases/release_*.json`, skip remote `.env` unless
  `-All` / `-EnvOnly`, snapshot `bakerysoftware` and run
  `scripts/run_migrations.php --mode=dreamhost-stage` when schema SQL changed
  after a prior baseline, and smoke `https://staging.sourflour.org/login.php`
  for the STAGING banner and `bakerysoftware`.
- `push.bat` now calls the staging push script. Explicit live `/bake` remains
  `.\scripts\push_sftp.ps1` only.
- Tests: `php tests/run_phase4_auto_deploy_tests.php` and
  `php tests/run_staging_env_tests.php`. Detail: `docs/PHASE4_STAGING_AUTO_DEPLOY.md`.

Exit gate: every staging deploy is target-checked, logged, and unable to reach
the live remote root or live DB.

Rollback: restore the prior staging code artifact and staging pre-deploy dump.
Disable staging auto-push with `storage/deploy/.auto_push_disabled`.

## Phase 5 — one controlled production promotion

Add controls to an existing administrator surface; do not create a new top-level
module.

- **On staging:** “Promote tested release” selects the current successful release.
- **On live:** “Pull approved release” shows the same release ID and manifest.

Both controls enqueue the same server-side CLI job. The web request never runs a
long import or file copy directly. A DreamHost cron/CLI worker uses a lock so one
promotion can run at a time.

Promotion sequence:

1. require administrator role, CSRF, typed release ID, and a second confirmation;
2. verify release is committed, staging-tested, and has immutable file hashes;
3. put no broad database overwrite command in the job;
4. create and verify a production backup plus checksum;
5. run production preflight and migration dry-run/status;
6. deploy backward-compatible code/schema in the release-defined order;
7. apply explicit reference-data migration files, if any;
8. run production health and critical read-only checks;
9. record actor, release ID, Git commit, files, migrations, backup, and result;
10. leave the last known-good code artifact available for rollback.

Destructive schema changes require a separately approved maintenance plan. A
normal promotion cannot include `DROP DATABASE`, a whole staging dump, or the
legacy local-to-production data push.

Exit gate: one low-risk release is promoted and independently verified; rollback
artifacts are present.

Rollback: restore previous code immediately. Restore the verified pre-release
database backup only when a migration/data change requires it and after the
owner authorizes that specific restore.

## Phase 6 — automatic backup system

Backups are independent of working databases:

| Backup | Frequency | Minimum policy |
|---|---|---|
| Nightly live snapshot | Nightly | Keep at least 14 successful snapshots initially |
| Weekly immutable live backup | Weekly | Keep 12 weeks; never overwrite a filename |
| Pre-production-release backup | Every release | Keep with the release record |
| Local staging checkpoint | Before refresh/risky development | Keep until the work is accepted |
| Staging pre-refresh backup | Every staging refresh | Keep recent successful restore points |

Each backup has a compressed SQL file, SHA-256 hash, timestamp, source database,
schema ledger, size, and verification status. Weekly production backups must also
be copied off the development PC. DreamHost’s short-term daily backups are a
useful extra layer, not the only recovery plan.

Run a monthly restore drill into a disposable local database. A backup is not
considered proven merely because `mysqldump` exited successfully.

Exit gate: the agent can select a backup, verify it, restore it into a disposable
database, and report expected core counts without touching live.

Phase 6 implementation (2026-08-18 local time):

- `run_nightly_data_cycle.ps1` creates one verified read-only snapshot and
  refreshes `bakerysf_local` plus `bakerysf_test` from that identical source.
  Local staging is preserved unless explicitly requested.
- `run_weekly_backup.ps1` preserves an immutable weekly snapshot pair, retains
  12, and supports `BAKERY_OFFSITE_BACKUP_DIR`.
- `verify_backup_restore.php` proves a backup in disposable
  `bakerysf_refresh_local`, checks captured core counts, records a receipt, and
  drops the database.
- `install_data_tasks.ps1` owns the nightly, weekly, and due-monthly Windows
  tasks; runtime logs and dumps stay outside Git.

Verification evidence (2026-08-18 local / 2026-08-19 UTC): all three Windows
tasks are installed and Ready. An existing verified snapshot created immutable
weekly backup `2026-W34_live_20260819_003445_phase2_baseline.sql.gz`; its restore
drill matched 107 customers, 54 products, 4,441 standing orders, 6 drivers, 105
default quantities, 11 users, and 878 daily orders, then dropped the disposable
database. The same snapshot successfully refreshed the mirror and test database
while preserving `bakerysf_stage_local`.

## Phase 7 — retire unsafe legacy paths and reconcile documentation

After the new flow is proven:

- remove production from editor hooks permanently;
- remove the destructive whole-local-DB → production action from the normal dev
  menu and require an exceptional recovery procedure if retained at all;
- disable runtime schema DDL in production and staging web requests;
- make the canonical migration runner support explicit `local-stage`, `test`,
  `dreamhost-stage`, and `production` modes with target verification;
- make direct local `USE_PROD_DB=true` time-limited and exceptional, or retire it;
- reconcile README, local setup, deployment, backup, and production docs;
- keep environment credentials outside Git and use distinct users per database.

Exit gate: there is one documented route for snapshots, one for tests, one for
staging deploy, and one for production promotion.

Phase 7 implementation (2026-08-18 local time): direct local-to-production DB
and live-file actions were removed from the normal developer menu. Canonical
operator instructions are now `DEV_WORKFLOW.md`, `DATA_OPERATIONS_RUNBOOK.md`,
and `PRODUCTION_DEPLOY.md`. Low-level legacy recovery tools remain quarantined
and unlinked rather than being deleted during stabilization.

## Agent execution protocol

Each phase is a separate Homebase mission. The agent must:

1. read this plan and the latest Homebase handoff;
2. state the exact targets before any destructive/import/deploy command;
3. complete only one phase or bounded sub-phase at a time;
4. verify the rollback artifact before mutation;
5. stop at the named owner gate rather than assuming production authority;
6. update this checklist and Homebase with files, commands, results, and hashes;
7. never hide a failed verification by advancing the phase.

The bakery owner should not need to run shell or Git commands. Owner input is
limited to approving these gates:

1. production-read access for the first verified backup;
2. creation/use of new DreamHost staging resources;
3. the first staging refresh from live;
4. the first production release promotion;
5. any production restore or destructive migration.

## Immediate next mission

Finish local gates, install the scheduled data tasks, commit the infrastructure
to the additive branch, then re-enable staging-only auto-push. A new clean,
committed staging deploy and phone acceptance are required before an immutable
candidate can exist. Production `bakery.sourflour.org/bake` remains untouched;
live execution is a separate mission even if promotion was discussed earlier.
