# Prompt 60 — Overnight cron, verified

Wave 4 (integration). `--agent=overnight-cron`. Owner ops task with an agent-prepared runbook and an in-app staleness check.

---

`scripts/demand_scheduler.php` and `scripts/staff_alert_digest.php` exist but the DreamHost cron install is still pending (`docs/CRON_KIT.md`). Page loads fill the horizon, so nobody notices when the cron is not running — until the first person opens a laptop late.

## Read first

- `docs/CRON_KIT.md`, `scripts/demand_scheduler.php`, `scripts/staff_alert_digest.php`
- `health_deploy.php`, `includes/dashboard_command_center.php` (tomorrow-readiness strip), `includes/staff_alerts.php`
- `tests/run_demand_scheduler_tests.php`, `tests/run_staff_alert_tests.php`

## Ship

1. Both scripts record `last_run_at` + outcome in `operational_events` (kind `cron_run`) and a small `storage/cron/<name>.json`.
2. `health_deploy.php` reports `cron.demand_scheduler.age_hours` / `cron.staff_alert_digest.age_hours`; dashboard shows a "Overnight generation stale (Nh)" warning chip when > 26h, wired into the staff alert bell as a warning fact.
3. `docs/CRON_KIT.md`: exact DreamHost crontab lines, expected log path, how to verify from `health_deploy.php`.

## Owner action

Install the crontab on DreamHost; confirm `health_deploy.php` shows both ages < 26h the next morning.

## Done when

The dashboard tells the truth about whether the night ran, and the runbook needs no owner interpretation.
