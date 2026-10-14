---
name: Git to Staging
description: >-
  Use when deploying SourFlour bakery code to hosted Staging, when asked to SFTP
  or WebFTP a change, or when a GitHub commit is treated as Staging or Live.
---
# Git to Staging

Use when moving SourFlour bakery code toward hosted Staging, when someone asks to SFTP/WebFTP/File Manager a change, or when a GitHub commit is treated as a deploy.

## Three layers

GitHub is not Staging. Staging is not Live.

- GitHub: https://github.com/dgabriner/bakery.git on **main**. Application source only. Additive branch. Push. SheepMiner/Bakery is old; do not clone it or open PRs there. Do not merge `chore/checkpoint-*` into main. Say `dgabriner/bakery#N` or the branch.
- Staging: https://staging.sourflour.org/ (DB bakerysoftware).
- Live: https://bakery.sourflour.org/bake/ (owner promote only). Never because you pushed.

Closeout Radar via DreamHost File Manager is the anti-pattern. Do not repeat it.

## Staging sync

A GitHub commit is not Staging until the files actually show there.

**Confirmed paths (either):**

1. Danny’s desktop Sync to staging / `push_sftp_stage`.
2. Owner-authorized cloud Cursor staging SFTP (only when Danny already put Staging secrets on that cloud agent). Prefer this when a PR claims cloud Staging sync and desktop is not in play.

**Unconfirmed:** Staging Git pull / DreamHost worker. Repo owns standing that up (**A**).

**Never:** Live SFTP, File Manager, WebFTP, or inventing Staging secrets. Do not treat a PR body (“Live not touched”, “cloud staging SFTP”) as proof. Curl both hosts (app-build meta and named includes) before you close the ship.

GitHub Action "Deploy DreamHost" targets Live with empty secrets. Unused. Not a Staging path. Live tree scripts (`push.bat`, `scripts/push_sftp.ps1`, `sftp_upload.py`, `.env.sftp`) are not for Staging. Desktop Staging uses `scripts/push_sftp_stage.ps1` with `.env.sftp.stage`.

## Live promote (owner)

**REQUIRED:** SourFlour Live promote. Do not clone it here.

Staging, then Manager, then the Staging to Live board, then **one Next click** (`HOSTED_PROMOTION.md`). Not a typed-confirm phrase. Not a Git push. Agents never click it.

Live app-build or includes can advance without Next. Tour pages Staging 200 / Live 404 do **not** prove Live was untouched. “Live not touched” is a claim until both hosts are checked.

## Staging-only pages

Tour/demo pages (`staging_update.php`, `oven_light.php`, `proof_window.php`) stay on Staging. Live returns 404 on all three. The skip-list `cursor/keep-tour-pages-off-live-0a8f` @ `30f591f` is on Staging (Staging `deploy_manifest.ps1` excludes the three tour pages; Repo confirmed Sep 9 and rechecked Oct 8), so that gate is met. After every Next, confirm the three still return 404 on Live. A PR that removes skip patterns from `deploy_manifest.ps1` reopens the gate. Name it by feature, not a bare “PR #6.” Next copies Staging onto Live.

## Notes

Baker formula units are already on Staging; do not re-teach that ship. Repo hands Handoff the eight-field after each Git push. Never hold SFTP keys, `.env.sftp.*`, MySQL passwords, or Live `/bake` upload.

Live DreamHost shell / cron paths: **REQUIRED:** SourFlour Staging vs Live (`dh_dp755h`, not Staging `bakeryOS`). Do not edit `CRON_KIT.md` placeholders as Live.

## Canonical path

Edit → test if you can (`tests/run_*.php` on bakerysf_test only) → commit → push additive branch on `dgabriner/bakery` → Staging sync (desktop or owner-authorized cloud Staging SFTP) → check Staging → owner Next → Live.

## Do not

Force-push. Merge `chore/checkpoint-*` into main. Run Live deploy scripts / Deploy DreamHost. Live SFTP. Import a staging DB onto Live. Point tests at Live. Promote Live. Teach typed-confirm. Remove tour-page skip patterns from `deploy_manifest.ps1`. Trust PR “Live not touched” without curling both hosts.

## Handoff (eight fields)

**REQUIRED:** Eight-field handoff. Handoff owns the checklist. Skills writes the playbook.
