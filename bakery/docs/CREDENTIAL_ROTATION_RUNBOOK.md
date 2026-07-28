# Credential Rotation Runbook

**Audience:** Sour Flour operator / admin  
**When:** Before or immediately after removing hardcoded secrets from the codebase  
**Agent policy:** Agents must **not** rotate production credentials or print secret values.

This runbook lists **what** to rotate and **where** evidence of exposure was found. It does **not** include secret values.

---

## 1. Inventory of credentials to rotate

| Credential | Where referenced (paths / names only) | Suggested action |
|------------|----------------------------------------|------------------|
| MySQL database password | `includes/config.php` (`DB_PASS` fallback); several debug/scripts with inline PDO password; `get_customers_no_address.php` | Change DB user password on host; update DreamHost panel / env only |
| MySQL connectivity assumptions | Host/user/db name fallbacks in `includes/config.php` | Confirm whether user should be rotated or restricted by host |
| SMTP password | `includes/email_config.php` (`SMTP_PASSWORD`); echoed in `test_email.php` troubleshooting text | Change mailbox / app password; update env only |
| Gmail OAuth client secret | `includes/gmail_oauth.php` (`CLIENT_SECRET`) | Rotate in Google Cloud Console; revoke old secret; store new secret outside web root / in env |
| Gmail OAuth client ID | Same file (`CLIENT_ID`) | Usually public-ish, but treat as sensitive config; update with secret |
| OAuth refresh/access tokens | Runtime `oauth_tokens.json` (if present on servers) | Delete old tokens; re-authorize after secret rotation |
| Google Maps API key | `includes/google_maps_config.php` (`GOOGLE_MAPS_API_KEY`); embedded into browser JS on map/route pages | Restrict by HTTP referrer + API; rotate key in Google Cloud; update env |

---

## 2. Recommended rotation order

1. **Confirm backups** of production DB and current working config (offline, not in git).
2. **Google Cloud:** rotate Maps key; restrict APIs/referrers; rotate OAuth client secret; invalidate old OAuth tokens on disk.
3. **Mailbox / Google Workspace:** rotate SMTP / app password used by PHPMailer.
4. **MySQL:** change `bakerysf` (or current) DB password; update only non-git production config (DreamHost panel env, or config file **outside** document root).
5. **Deploy** application build that **refuses to start** without env/external config (no production fallbacks) — Checkpoint 0B+.
6. **Verify** login to app DB from production host only; verify invoice email on staging first; verify Maps loads with restricted key.
7. **Scan** web root for leftover `debug*`, `test*`, `oauth_tokens.json`, and `.sql` dumps; remove or block.

---

## 3. DreamHost / Apache storage options (for after rotation)

Prefer (in order):

1. **Apache / panel environment variables** injected into PHP (`SetEnv` / DreamHost equivalent) for `DB_*`, mail, OAuth, Maps.
2. **Config file outside document root** (e.g. sibling to `bakery/` web folder), readable only by the app user, required by `config.php`.
3. **Local `.env` for development only**, loaded explicitly when `APP_ENV=local`, never deployed with real production secrets.

Do **not** rely on hardcoded PHP fallbacks for production.

---

## 4. Verification checklist (no secret output)

- [ ] Application connects to DB using env/external config only  
- [ ] Grep/search of deployed tree finds **no** password/secret string literals  
- [ ] Old Maps key rejected or unused  
- [ ] Old OAuth secret cannot obtain tokens  
- [ ] Old SMTP password cannot send mail  
- [ ] `oauth_tokens.json` not web-accessible  
- [ ] Diagnostics (`test.php`, `run_sql_setup.php`, etc.) not publicly reachable  

---

## 5. If a secret was committed to git history

If secrets ever land in a pushed commit:

1. Rotate the secret immediately (treat as public).  
2. Purge history or accept rotation-only mitigation (rotation is mandatory either way).  
3. Do not assume `.gitignore` alone remediates prior pushes.

As of Checkpoint 0A inspection, **`bakery/` had zero tracked files**, so bakery secrets were not in git history yet. Keep it that way until sanitization.
