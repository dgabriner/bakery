# Cron Kit: overnight jobs on DreamHost

Three scripts are designed to run unattended on DreamHost. Page views do not
create dated orders. After 3:00 AM America/Los_Angeles, a staff page sends one
same-origin POST to `demand_self_heal.php` when today's sell day has no
successful demand build. That POST is the backup when the cron file is missing.
The dual survey does not open on a page view.

**Owner installs the crontab on DreamHost.** Agents ship the runbook and the
in-app self-heal. Staging and Live cron installs are not performed from GitHub.

## Jobs

| Script | What it does | Suggested cadence |
|---|---|---|
| `scripts/demand_scheduler.php` | Materializes dated orders from standing orders over the rolling horizon; records `cron_run` + `storage/cron/demand_scheduler.json` | Nightly, early morning (PT) |
| `scripts/staff_alert_digest.php` | Emails one digest of critical/warning alerts to active administrators/managers. **Silent when clean** but still stamps `cron_run`. | Morning, before the manager's first coffee (PT) |
| `scripts/survey_dual_open.php` | Opens the next sell day's dual survey (lock stores and set order) for HQ and each active driver so end-of-day texts can carry the Ajustes link. Records `cron_run`. Does not send texts. | 5:00 PM America/Los_Angeles |

## Install (DreamHost panel → Cron Jobs)

The Live tree is `/home/dh_dp755h/bakery.sourflour.org/bake`. Add this line if it is not already there. The panel clock must be America/Los_Angeles so 2:30 is 2:30 AM PT. Do not edit a line that already matches.

```cron
# Demand horizon fill, 02:30 America/Los_Angeles
30 2 * * * /usr/local/bin/php /home/dh_dp755h/bakery.sourflour.org/bake/scripts/demand_scheduler.php >> /home/dh_dp755h/cron_demand.log 2>&1

# Staff alert digest, 06:00
0 6 * * * /usr/local/bin/php /home/dh_dp755h/bakery.sourflour.org/bake/scripts/staff_alert_digest.php >> /home/dh_dp755h/cron_digest.log 2>&1

# Next-day dual survey, 17:00 America/Los_Angeles
0 17 * * * /usr/local/bin/php /home/dh_dp755h/bakery.sourflour.org/bake/scripts/survey_dual_open.php
```

The 5:00 PM line is also in the script header. Do not open the dual survey from a GET.

Log paths on the shell host:
- `/home/dh_dp755h/cron_demand.log`
- `/home/dh_dp755h/cron_digest.log`
- App stamps: `operational_events` row `cron_run` (the board reads this) and `bake/storage/cron/demand_scheduler.json`, `bake/storage/cron/staff_alert_digest.json`

Both scripts refuse to run against local/test targets unless `--force`. On DreamHost they run as-is.

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
3. **Dashboard / Daily Run:** the board shows the last run in Pacific time.
   It warns only when the 2:30 AM PT run is already due (after 3:30 AM PT)
   and tomorrow has no dated orders. A missing `storage/cron/*.json` file
   is not a warning when the database heartbeat or tomorrow's orders exist.
4. Digest delivery still also writes `operational_events.staff_alert_digest_sent` when email goes out;
   clean nights only write `cron_run` (that is still success).

## If the 2:30 AM job did not record a run

Staff page GETs do not create dated orders. After 3:00 AM America/Los_Angeles, a signed-in administrator, manager, driver, driver assistant, or sales user loads a staff page (Manager, Daily Run, Driver, route pages, Sales). The browser sends one same-origin POST to `demand_self_heal.php` with the CSRF token. That request runs the same horizon fill as `scripts/demand_scheduler.php`: dated orders from standing, pan carry-forward, and `bakery_route_load_rebuild`. It writes a `cron_run` row with `source=self_heal`. It does nothing when today's sell day already has a successful `demand_scheduler` run, including a run from this POST. It fills customers who have no dated order. It does not overwrite existing orders, one-time skips, or exceptions. A second request the same day is a no-op. The endpoint has no other action, and it does not send email or SMS.

Live file promotion does not copy `scripts/`. The self-heal lives in `includes/daily_order_generation.php` and `demand_self_heal.php`, which do copy. The cron line is still the overnight path when the file is on the host and the panel runs it.

Danny: open the DreamHost panel, Cron Jobs, for user `dh_dp755h`. The panel clock must be America/Los_Angeles. Confirm this line is present and unchanged:

```cron
30 2 * * * /usr/local/bin/php /home/dh_dp755h/bakery.sourflour.org/bake/scripts/demand_scheduler.php >> /home/dh_dp755h/cron_demand.log 2>&1
```

Then read `/home/dh_dp755h/cron_demand.log`. A missing script, `CLI only`, or `Configuration error: required database environment variables are not set` means the cron process never reached a database stamp. The self-heal covers the sell day after 3:00 AM PT once a staff page loads. Leave the cron line in place.

## Notes

- Test delivery without touching recipient lists: `--to=you@example.org`.
- Both scripts exit 0 on success; non-zero exits mean misconfiguration and land in the cron log.
- **Do not claim Staging/Live cron install from a GitHub-only agent run.**
