# Cron Kit — overnight jobs on DreamHost

Two scripts are designed to run unattended on DreamHost. Page loads already fill the
demand horizon lazily, so cron makes the system proactive instead of reactive.

## Jobs

| Script | What it does | Suggested cadence |
|---|---|---|
| `scripts/demand_scheduler.php` | Materializes dated orders from standing orders over the rolling horizon; records events; prepares route demand | Nightly, early morning (PT) |
| `scripts/staff_alert_digest.php` | Emails one digest of critical/warning alerts to active administrators/managers. **Silent when clean.** | Morning, before the manager's first coffee (PT) |

## Install (DreamHost panel → Cron Jobs)

```cron
# Demand horizon fill — 02:30 America/Los_Angeles server time
30 2 * * * /usr/local/bin/php /home/YOUR_USER/bakery.sourflour.org/bake/scripts/demand_scheduler.php >> /home/YOUR_USER/cron_demand.log 2>&1

# Staff alert digest — 06:00
0 6 * * * /usr/local/bin/php /home/YOUR_USER/bakery.sourflour.org/bake/scripts/staff_alert_digest.php >> /home/YOUR_USER/cron_digest.log 2>&1
```

Replace `YOUR_USER` with the shell user that hosts `bakery.sourflour.org`. Both scripts
refuse to run against local/test targets unless forced — on DreamHost they run as-is.

## Verify after installing

1. **Local dress rehearsal** (safe, uses `MAIL_DRIVER=log`):
   ```powershell
   $env:DB_NAME='bakerysf_test'; $env:USE_PROD_DB='false'
   php scripts\staff_alert_digest.php --force --to=danny@sourflour.org --json
   Get-Content logs\mail.log -Tail 3     # expect a staff_alert_digest line
   ```
2. **On production**, after the first scheduled run:
   - Digest: query `operational_events` for `staff_alert_digest_sent` (one row per delivered
     digest; no row means the day was clean — that is success too).
   - Demand: Daily Run stage 1 should already be ready each morning without anyone opening
     the page first.
3. Email transport follows `.env`: real SMTP/Gmail OAuth in production, log-only on
   local/staging (`MAIL_DRIVER=log` appends to `logs/mail.log`).

## Notes

- The digest is pull-free for recipients but still needs cron to push. Without cron,
  the nav bell remains the fallback surface on every staff page.
- Test delivery without touching recipient lists: add `--to=danny@sourflour.org`.
- Both scripts exit 0 and print quiet JSON under `--json`; non-zero exits mean
  misconfiguration (bad target, zero eligible recipients) and land in the cron log.
