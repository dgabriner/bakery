# Cron Kit — overnight jobs on DreamHost

Two scripts are designed to run unattended on DreamHost. Page loads already fill the
demand horizon lazily, so cron makes the system proactive instead of reactive.

**Owner installs the crontab on DreamHost.** Agents ship the runbook + in-app
staleness check only — Staging and Live cron installs are not performed from GitHub.

## Jobs

| Script | What it does | Suggested cadence |
|---|---|---|
| `scripts/demand_scheduler.php` | Materializes dated orders from standing orders over the rolling horizon; records `cron_run` + `storage/cron/demand_scheduler.json` | Nightly, early morning (PT) |
| `scripts/staff_alert_digest.php` | Emails one digest of critical/warning alerts to active administrators/managers. **Silent when clean** but still stamps `cron_run`. | Morning, before the manager's first coffee (PT) |

## Install (DreamHost panel → Cron Jobs)

Exact lines (replace `YOUR_USER`):

```cron
# Demand horizon fill — 02:30 America/Los_Angeles server time
30 2 * * * /usr/local/bin/php /home/YOUR_USER/bakery.sourflour.org/bake/scripts/demand_scheduler.php >> /home/YOUR_USER/cron_demand.log 2>&1

# Staff alert digest — 06:00
0 6 * * * /usr/local/bin/php /home/YOUR_USER/bakery.sourflour.org/bake/scripts/staff_alert_digest.php >> /home/YOUR_USER/cron_digest.log 2>&1
```

Log paths on the shell host:
- `/home/YOUR_USER/cron_demand.log`
- `/home/YOUR_USER/cron_digest.log`
- App stamps: `bake/storage/cron/demand_scheduler.json`, `bake/storage/cron/staff_alert_digest.json`

Both scripts refuse to run against local/test targets unless `--force` — on DreamHost they run as-is.

## Verify after installing

1. **Local dress rehearsal** (safe, uses `MAIL_DRIVER=log`):
   ```bash
   cd bakery
   DB_NAME=bakerysf_test USE_PROD_DB=false php scripts/demand_scheduler.php --force --json
   DB_NAME=bakerysf_test USE_PROD_DB=false php scripts/staff_alert_digest.php --force --to=you@example.org --json
   cat storage/cron/demand_scheduler.json
   cat storage/cron/staff_alert_digest.json
   ```
2. **From the app** open `health_deploy.php` and confirm:
   - `cron.demand_scheduler.age_hours=<number under 26>`
   - `cron.staff_alert_digest.age_hours=<number under 26>`
   - `null` means that job has never stamped a run on this host.
3. **Dashboard / staff bell:** if demand age > 26h, Command Center shows
   “Overnight generation stale (Nh)” as a warning fact (same queue as other staff alerts).
4. Digest delivery still also writes `operational_events.staff_alert_digest_sent` when email goes out;
   clean nights only write `cron_run` (that is still success).

## Notes

- Without cron, page loads keep filling demand lazily and the nav bell remains the fallback.
- Test delivery without touching recipient lists: `--to=you@example.org`.
- Both scripts exit 0 on success; non-zero exits mean misconfiguration and land in the cron log.
- **Do not claim Staging/Live cron install from a GitHub-only agent run.**
