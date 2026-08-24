# GitHub → DreamHost deploy (phone / Cloud)

Merge-triggered (or manual) SFTP deploy so Cloud agents on your phone can ship without a Windows PC.

Local `push.bat` / Cursor hooks / `.env.sftp` stay useful at the desk. This path is for **merge = live** when you are away from the machine.

## One-time setup

### 1. Add GitHub Actions config

Repo → **Settings → Secrets and variables → Actions**.

Use **Variables** for the three non-password fields (you can see and confirm the values). Use a **Secret** only for the password (the edit box always looks empty after save — that is normal).

**Variables** tab → **New repository variable**:

| Name | Value |
|------|--------|
| `SFTP_HOST` | `iad1-shared-b7-08.dreamhost.com` |
| `SFTP_USER` | `dh_dp755h` |
| `SFTP_REMOTE_ROOT` | `bakery.sourflour.org/bake` |

**Secrets** tab → **New repository secret**:

| Name | Value |
|------|--------|
| `SFTP_PASSWORD` | from your local `bakery/.env.sftp` (or DreamHost panel) |

Never commit the password. Names must match exactly (no spaces).

If you previously saved blank secret values by clicking Update without pasting, delete those secrets and recreate them, or put host/user/root on the **Variables** tab instead.

### 2. Deploy branch

The workflow [`.github/workflows/deploy-dreamhost.yml`](../../.github/workflows/deploy-dreamhost.yml) runs on push to:

- `chore/checkpoint-0a-repo-safety` (current default)
- `main`
- `live`

Merge bakery changes into the default branch (or run the workflow manually). Optional: add branch protection + required review before merge.

### 3. Confirm SF 2.0 on the cloud site

After a successful deploy, open `https://bakery.sourflour.org/bake/login.php` (no login needed). You should see a terracotta **SF 2.0** label under the logos. After login, workspace nav shows a cream **SF 2.0** badge.

If the marker is missing, the host is still on an older build — re-run **Deploy DreamHost** with mode `all`.

## Phone / Cloud workflow

1. Cloud agent opens a PR with bakery changes.
2. You review and **merge** into `chore/checkpoint-0a-repo-safety` (GitHub mobile is fine).
3. Actions runs **Deploy DreamHost** and SFTPs changed deployable files.
4. Check the run log, then confirm **SF 2.0** on `https://bakery.sourflour.org/bake/login.php`.

### Manual run (no merge)

GitHub → **Actions → Deploy DreamHost → Run workflow**

- `changed` — files changed vs previous commit (default)
- `all` — every file in the deploy manifest (full sync)

## What gets uploaded

Same rules as local `push_sftp.ps1` / `deploy_manifest.ps1`:

- Manifest root PHP pages, `includes/`, `css/`, `assets/`
- Optional `vendor/phpmailer`, `uploads/driver_photos/.htaccess`
- Excludes debug/backup variants, `.env`, SQL dumps, scripts, tests

Listing for CI: `bakery/scripts/list_deploy_files.py`  
Upload: `bakery/scripts/sftp_upload.py` (unchanged)

Schema SQL is **not** applied by this workflow (same as local SFTP push).

## Local desk vs Cloud

| Path | When |
|------|------|
| `push.bat` / auto-push hooks | Windows machine on, desk work |
| This GitHub Action | Phone / Cloud; PC can be off |
| Ask Cloud agent to SFTP | Only if SFTP secrets exist in the **Cloud environment** (separate from GitHub secrets) |

## Dry-run locally (Linux / Cloud)

```bash
pip install paramiko
python bakery/scripts/list_deploy_files.py --bakery-root bakery --all | head
python bakery/scripts/list_deploy_files.py --bakery-root bakery --git-from HEAD~1 --git-to HEAD
# With secrets in the environment:
# python bakery/scripts/sftp_upload.py --local-root bakery --list /tmp/files.txt --dry-run
```

## Safety

- Secrets only in GitHub Actions (or Cloud env) — never in git
- Never upload local `.env` to DreamHost
- Prefer `changed` after the first full sync (`all` once if the server is behind)
- Database / migrations remain a separate, intentional step (see [PRODUCTION_DEPLOY.md](PRODUCTION_DEPLOY.md))
