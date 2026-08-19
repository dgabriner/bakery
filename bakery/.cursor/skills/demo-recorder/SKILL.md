---
name: demo-recorder
description: >-
  Records Sour Flour OS usage walkthroughs as MP4 videos. Use when the user
  asks to demo, screen-record, record a walkthrough, produce an mp4 or video of
  login, Daily Run, Driver Assignment, adjusting the route, or any other bakery
  app usage; or when they want English/Spanish walkthroughs on the website gallery.
---

# Demo recorder

Walk a real local browser through a usage, then write an **MP4**. Default both English and Spanish. Publish into the in-app gallery.

```text
php scripts/demo_record.php list
php scripts/demo_record.php all --publish
php scripts/demo_record.php drivers --locale=es --publish
php scripts/demo_record.php adjust-route --locale=both --publish
php scripts/demo_record.php login --headed
```

Working copies: `storage/demo-recordings/<id>-<locale>-<timestamp>.mp4`  
Gallery copies: `assets/walkthroughs/<id>-en.mp4` and `<id>-es.mp4`  
Website: Insights → Walkthroughs (`walkthroughs.php`)  
Driver SMS page (Spanish only): `guias.php` (`php scripts/demo_record.php drivers` records that catalog, not staff Driver Assignment).

## Rules

- `bakerysf_local` only (production-data mirror). Never live `bakerysf`. Never `bakerysf_test`.
- Do not print 4-digit codes. Scenarios use `{{ADMIN_CODE}}` / `{{DRIVER_CODE}}` / `{{DRIVER_ID}}` / `{{DATE}}`.
- Captions are `{ "en": "...", "es": "..." }`. The recorder switches the login language control so the cookie survives staff login.
- Adjust-route snapshots `route_order` and restores it after each locale.
- Do not deploy. Do not enable auto-push.

## When the user names a usage

1. Reuse `tools/demo-recorder/scenarios/*.json` if one matches.
2. Otherwise write a new JSON (temp under `storage/demo-recordings/` is fine).
3. Run `php scripts/demo_record.php <id-or-path> --publish`.
4. Return the MP4 path and the Walkthroughs page. Do not paste the video bytes into chat.

## Scenario JSON

```json
{
  "id": "my-usage",
  "title": { "en": "Short title", "es": "Título corto" },
  "prepare": "users",
  "viewport": { "width": 1280, "height": 720 },
  "slowMo": 280,
  "captionHoldMs": 800,
  "endHoldMs": 1600,
  "steps": [
    { "action": "goto", "path": "login.php", "caption": { "en": "Open staff login", "es": "Abre la entrada del personal" } },
    { "action": "fill", "selector": "#code", "value": "{{ADMIN_CODE}}", "delay": 200 }
  ]
}
```

`prepare`: `users` (staff codes + a real dated route if one exists), `driver-login` / `driver-route` (driver code + remaining stops), `adjust-route` (requires 2+ remaining stops), or `skip-stop` (snapshots remaining stops). Adjust and skip restore `route_order` / ENUM-legal `delivery_status` afterward.

Spanish MP4s mix an **audible narrator** (edge-tts `es-MX` neural, else Windows SAPI Spanish) timed to captions. Captions stay on screen as backup. Do not speak 4-digit login codes. English recordings stay silent unless you add an English voice later.

Driver SMS page: `guias.php` (Spanish, no login). Catalog: `php scripts/demo_record.php drivers --locale=es --publish`.

On bakerysf_local, `delivery_status` may still be `pending|in_transit|delivered|failed|rescheduled` until migration 046 adds `cancelled`. Skip in the app writes `cancelled`. The skip walkthrough submits a reason and confirm, then restore writes the snapshot back. Restore only writes ENUM-legal values; `cancelled` maps to `pending` when the column cannot store it; empty statuses are skipped. Discover remaining stops with `pending` / `in_transit` only. TODAY/TOMORROW prefer consecutive dated days with remaining stops for the same driver.

Complete-stop: do not click `#deliveryInvoiceConfirmBtn`. File chooser: do not press Escape (the delivery modal closes).

Actions: `goto`, `fill`, `click`, `clickIf`, `hover`, `press`, `wait`, `waitForSelector`, `waitForURL`, `waitForText`, `scroll`, `caption`, `reload`. `click` may set `"force": true` when sticky driver chrome covers the control. Optional `narration` is a slightly more spoken version of `caption`.

Login is `#code` and auto-submits at 4 digits. Driver route adjust: `button.route-btn--adjust.change-next-btn`, `#stopList .stop-item--upcoming .stop-item-name`, `#routeAdjustSave` with `"force": true`. Prefer ids/data attributes over translated button text.

## Checks

```text
php tests/run_demo_recorder_tests.php
php tests/run_navigation_tests.php
php tests/run_i18n_tests.php
```
