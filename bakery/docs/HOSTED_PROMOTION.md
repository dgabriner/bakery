# Hosted Staging to Live promotion

The normal release workflow is:

1. Develop locally. Changes may sync to Staging.
2. Test at `https://staging.sourflour.org/`, including on the phone.
3. Open **Staging → Manager**. The **Staging → Live** board is at the top.
4. Do the single **Next** step it names. If Live is behind, click the database button. Otherwise click **Send files to Live**. One click queues the job; there is no typed confirm.
5. After you click, the board follows that worker until it finishes. Refresh the schema comparison yourself if the database card still looks behind after a succeeded update.
6. Finish only when the database shows **Match** and the file worker shows **Succeeded**.
7. Open **History** under the two cards only when you need the trail: each send is a collapsed row with time, success or failure, who queued it, and the exact file list or migration id. Filters keep failures easy to find without filling the board.

No Git commit, Git HEAD match, release ID, localhost PowerShell process, or return trip to localhost is required.

## What the button does

- Staging hashes the exact deployable files currently being tested and writes one approval manifest.
- The Live account reads that manifest and those files through a restricted, read-only `rrsync` key.
- Live compares the Staging snapshot to its last successful release and fetches only changed files.
- Before changing anything, Live takes a fresh production database backup and copies only the changed Live files to `/home/dh_dp755h/bakery-release-backups/RELEASE_ID/`.
- Every fetched file is checked against the approved SHA-256 hash and every changed PHP file is linted.
- Files are replaced atomically, then the Live login health check must return HTTP 200.
- If deployment or health verification fails after replacement starts, changed files are restored automatically. If no files changed, Live performs only the health check and records that no transfer was needed.

Production `.env`, databases, `storage/`, uploads, scripts, tests, and documentation are never copied from Staging by this workflow. Database migrations remain a separate explicit production gate; a file promotion does not alter schema.

## Hosted additive database migration

For a new migration, the Staging sync publishes the SQL file to a private Staging vault. Through the existing `bakeryOS` SSH account it takes a verified `bakerysoftware` checkpoint on DreamHost, then applies those same private bytes beside the database. The workstation no longer needs remote MySQL authorization. Migrations `055` and later must pass the shared hosted portability policy before Staging accepts them. After phone acceptance, the Manager board offers a database action when Live is additively behind. The click queues the remaining safe updates the board already listed. It does not re-fetch Live schema or demand that the posted filename still match a second comparison. There is no migration picker.

Additive migrations are resumable when runtime schema safeguards created an
object before its ledger row. Each column is a separate statement; duplicate
column/index errors are accepted only after `INFORMATION_SCHEMA` verifies that
the named object is already present. Any other SQL error still stops the job.

The separate Live worker accepts additive `CREATE TABLE IF NOT EXISTS`, `CREATE INDEX`, `ALTER TABLE ... ADD`, enum-widening `MODIFY COLUMN`, `INSERT IGNORE`, and limited repair `UPDATE` statements used by catch-up migrations. It refuses drops, truncates, renames, deletes, destructive alterations, and cross-version hazards such as defaults on `TEXT`/`BLOB` columns. It checks the SHA-256 source, confirms the target is exactly `bakerysf`, takes and verifies a fresh production backup **before creating even its own migration ledger**, holds a database lock, runs the one approved SQL file once, and records its immutable ID in `schema_migrations`.

The status record includes the approval release ID, phase, and statement progress. A stale success from an older approval is shown as waiting, not as success for the new job. If DDL fails after one or more statements, the status says exactly how far it got and requires a reviewed forward repair; it does not claim that a database rollback occurred.

Schema compare uses base tables only (not views). View columns such as `v_daily_routes.*` are stripped on both sides so an older Live report cannot force Stop. When tables/columns/indexes match, the board shows **Match** even if migration ledger IDs differ.

Staging Manager shows a **Staging → Live** board with one Next step. Database states are:

- **Match** — same tables, columns, and indexes.
- **Live needs an update** — Staging is a strict additive superset. Appending ENUM values (for example `sfb_offerings.kind` gaining `donation` and `credits`) counts as behind, not Stop.
- **Stop — mismatch** — Live has extra columns or different types. Extra indexes on Live are shown, but they do not block an additive update. Reordered or removed ENUM values still Stop.
- **Can't compare yet** — Staging could not read Live’s report (missing file, timeout, or refused). Refresh. Waiting does not create the report. A succeeded Live migration is not a schema compare. After a succeeded update, Staging must fetch a fresh Live report (`schema_status.php?refresh=1`); an old cached report will still look behind.

Live caches the schema report for a few minutes so Staging is not blocked on a full `INFORMATION_SCHEMA` scan every click. Staging also keeps a short cache after a successful read.

New `database/schema/NNN_*.sql` files take the next unused number (`php scripts/next_schema_migration.php --name=slug`). Do not reuse 010, 021, 025, 061, 062, or 065 — competing agents already collided there. Do not rename applied files; Live already recorded those ids. Historical pairs stay; 068+ must be unique.

MariaDB DDL is not generally reversible as a transaction. If a migration fails partway through, the worker stops and reports it; it never “fixes” the situation by restoring or importing an entire production database. The recovery path is a reviewed forward migration, using the verified backup only if a separately authorized disaster restore is genuinely needed.

## Git's role

Git records and coordinates development. Commit finished work at sensible checkpoints and push it to GitHub for history and collaboration. Git is not the mechanism that moves an already-tested Staging version to Live, so an unrelated new commit or dirty local worktree cannot invalidate a hosted promotion.

## Hosted components

- Private Staging export: `/home/bakeryOS/.sourflour-promotion-export/`
- Live worker: `/home/dh_dp755h/bin/hosted_promotion_worker.php`
- Stable Live migration wrapper: `/home/dh_dp755h/bin/hosted_migration_worker.php`
- Deployable migration runtime: `/home/dh_dp755h/bakery.sourflour.org/bake/includes/hosted_migration_runtime.php`
- Live worker cron: every minute
- Live status: `storage/deploy/HOSTED_PROMOTION_STATUS.json`
- Live promotion history: `storage/deploy/HOSTED_PROMOTION_HISTORY.json`
- Staging operation history: `/home/bakeryOS/.sourflour-promotion-export/operation_history.json`
- Non-sensitive status endpoint: `/bake/deploy_status.php`
- Live schema inventory: `/bake/schema_status.php` (Live host only; no row counts)
- Live migration status: `/bake/migration_status.php`
- Live migration history: `storage/deploy/HOSTED_MIGRATION_HISTORY.json`
- Worker log: `/home/dh_dp755h/bakery-promotion.log`
- Migration worker log: `/home/dh_dp755h/bakery-migration.log`
- Pre-promotion file backups: `/home/dh_dp755h/bakery-release-backups/`

The migration wrapper is intentionally tiny and stable. Install or update it once outside the web root; subsequent normal file promotions update the runtime safely with the application. Its private environment must name the exact Live root, the Staging read-only SSH source, a verified backup command, and either the Live app's `DB_*` variables or the guarded `PROD_DB_*` equivalents. The worker refuses every database name except `bakerysf`.

```cron
* * * * * /usr/local/bin/php /home/dh_dp755h/bin/hosted_migration_worker.php >> /home/dh_dp755h/bakery-migration.log 2>&1
```

The first installation of the wrapper is a controlled hosting change. Do not copy it through the Staging file manifest (scripts are deliberately excluded), and do not point any editor hook at Live.

Before enabling cron, run the non-mutating installation preflight. It verifies
the exact Live root, required tools, readable Staging key, database environment,
and a read-only `SELECT DATABASE()` result of `bakerysf`. It does not fetch or
approve a migration, execute the backup command, acquire the schema lock, or run
DDL.

```sh
/usr/local/bin/php /home/dh_dp755h/bin/hosted_migration_worker.php --preflight
```

The expected JSON includes `"status":"ok"`, `"target":"bakerysf"`,
`"backup_executed":false`, and `"schema_changed":false`. Enable the cron only
after that exact result.

The former local candidate/HEAD promotion scripts remain available only as recovery tooling while this hosted path is proven. They are not part of the normal operator workflow.
