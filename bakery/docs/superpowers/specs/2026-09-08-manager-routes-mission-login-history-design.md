# Manager Routes mission board + Login History action trail

## Goal

Give Danny and Laura a simple, mobile-friendly place to see where each driver is on today’s route mission (stop order + timing), and make Login History’s full action/activity trail easy to read without hunting.

## Non-goals

- No new top-level module or page.
- No GPS-first board.
- No new route edit surface on Manager phone (view-only + existing deep links).
- No schema migration required.

## Part A — Manager Routes mission board

**Host:** existing phone `manager.php` → Routes tab (`includes/manager_phone.php`).

**Per driver card**

- Glance: name · `N of M` · current/next stop · last delivered time (or Not started / Done).
- Expanded by default when the route is still open: **Done so far** (order, store, actual time) then **Still to deliver** (remaining order, store, scheduled time when present).
- Mark the current/next stop once.
- Unassigned panel stays on top when needed.
- COD turn-in and closeout stay available but sit below the mission timeline.
- **Open route** remains the escape hatch to `driver.php`.

**Data**

- Enrich stop rows with `route_order`, `scheduled_delivery_time`, `actual_delivery_time`, `delivery_status`.
- Derive phases from assignment status only (no new tables).
- Sort drivers: in progress → not started → done → empty.

**i18n:** additive keys in `lang/en.php` and `lang/es.php`.

## Part B — Login History action history

**Host:** existing admin `login_history.php` + `includes/login_history_insights.php`.

**Improvements**

1. URL-backed timeline filter (`timeline=all|session|navigation|action`) so Actions sticks and is shareable.
2. When filtering Actions (or All), raise fetch/display caps and add **Load older** via `timeline_offset` instead of a dead-end “older events omitted” note.
3. Investigation links land on `#activity-stream`; session records offer **Investigate actions** (`timeline=action`) and an inline expandable session trail (pages for that `login_audit_id`).
4. Denser action-row styling so the trail is scannable.

**Auth:** remains administrator-only. Manager phone is for Laura; Login History stays Danny’s floor audit.

## Testing

- Extend `tests/run_manager_phone_tests.php` and `tests/run_login_history_tests.php`.
- Run mapped suites via `bash scripts/run_test_gate.sh --files=…`.

## Deploy

- Git branch + PR.
- Staging via `cloud_agent_stage.py`.
- Live via `--queue-live` (owner authorized “Stage and live”); no Live SFTP.
