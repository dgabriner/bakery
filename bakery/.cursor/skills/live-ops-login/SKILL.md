---
name: live-ops-login
description: >-
  Log into live Sour Flour OS (bakery.sourflour.org/bake) for read-only ops
  checks — Login History, Bakery Manager routes, Daily Run. Use when the owner
  asks who is signed in, how routes are doing, live deliveries, or to open the
  live site. Prefer env secrets; never commit login codes to Git.
---

# Live ops login

Owner-authorized for cloud agents. **Read-only ops** unless the owner clearly asks for a live write.

## Credentials (never commit values)

Read from the Cloud Agent environment:

| Env var | Account | When to use |
|---------|---------|-------------|
| `BAKERY_LIVE_AGENT_CODE` | **Cursor Agent** · manager · `cursor.agent@sourflour.local` | Default for Manager / routes / Daily Run |
| `BAKERY_LIVE_ADMIN_CODE` | **Danny** · administrator | Login History, Users, admin-only screens |

If a secret is missing, check `/cursor/stores/self/live-bakery-login.md` when present, then ask the owner — do **not** scrape old transcripts into Git.

Fallback account was created on live 2026-08-25 by a prior agent (manager role, code ends `••83`). Admin code ends `••41`.

## Live URLs

- App root: `https://bakery.sourflour.org/bake/`
- Login: `https://bakery.sourflour.org/bake/login.php`

Staging (`bakerysoftware` / staging SFTP) is **not** live progress — delivery statuses there stay `pending`. Use live for “how are we doing today?”

## HTTP session (canonical)

```text
GET  /bake/login.php
→ csrf_token from name="csrf_token"
POST /bake/login.php  csrf_token=…&code=…&next=/manager.php
→ session cookie PHPSESSID
```

Notes:

- Prefer Python `urllib` + `http.cookiejar` (or browser computer-use).
- After POST, a redirect to `https://bakery.sourflour.org/manager.php` (missing `/bake`) may 404; cookies are still set — continue with `/bake/manager.php`.
- Manager cannot open `login_history.php` (403) — switch to admin code for that page.

## Pages for today’s ops

Use Pacific date `YYYY-MM-DD`:

| Need | Path |
|------|------|
| Who’s signed in | `/bake/login_history.php` (admin) |
| Route scorecard + driver board | `/bake/manager.php?date=…` |
| Stop-level routes | `/bake/route_manager.php?date=…` (JS-filled; scorecard is more reliable) |
| Operating checklist | `/bake/daily_run.php?date=…` |

Manager scorecard fields that matter: Orders ready, Routes assigned (N/M), Driver progress (delivered / open / failed), Closeout, Daily route table (per-driver Stops / Open / Delivered).

## Safety

- Do not store codes in commits, PR bodies, or new docs beyond secret **names**.
- Do not deploy Live; do not request prod MySQL or SFTP for a login check.
- Unset any temporary `USE_PROD_DB` after authorized DB work (separate from this skill).
