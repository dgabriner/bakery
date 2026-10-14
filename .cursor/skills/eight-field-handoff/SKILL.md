---
name: Eight-field handoff
description: >-
  Use when a Bakery code change needs the eight-field Git→Staging closeout,
  evidence for a Live-not-touched claim, or a PR event repeats the same tip SHA.
  Not for source coding, Staging sync execution, or Danny’s Live Next-click.
---
# Eight-field handoff

Use when a Bakery code change needs the eight-field Git→Staging closeout, evidence for a Live-not-touched claim, or a PR event repeats the same tip SHA. Not source coding. Not Staging sync execution. Not Danny’s Live Next-click.

## Who

**Handoff**. Skills writes this playbook. **Repo** hands this checklist after each Git push. **REQUIRED:** Git to Staging for the ship path. Do not clone it.

Canonical remote: https://github.com/dgabriner/bakery.git on **main**. A GitHub commit is not Staging until files show.

**Staging sync:** Danny’s desktop Sync to staging, **or** owner-authorized cloud Cursor staging SFTP (secrets already on that agent). Never Live SFTP. Never File Manager. Never click Live / Next.

## Same-SHA debounce

PR watches fire several events (push, comment, review, CI) for one tip. Before doing any work:

1. Read the PR’s current tip SHA.
2. If it equals the last SHA you reported or silenced **and** that was within the last 15 minutes, log one `SILENT_NOISE <branch>@<sha>` line in your memory and exit. No curls, no packet, no ping.
3. Otherwise run the full eight fields below and curl **both** hosts. Record the SHA and time so the next event can debounce.

A new SHA, or the same SHA after 15 minutes, always gets the full check. Debounce never skips field 7 on a real change.

## Eight fields

1. Mission
2. Branch + SHA (or not pushed)
3. Files to verify
4. Tests run / not
5. Whether Staging was asked to sync (and how: desktop vs cloud Staging SFTP)
6. Whether staging.sourflour.org shows the change (curl app-build / named includes; login-gated UI may still be inconclusive)
7. Live not touched (**claim only until verified**: curl Live `/bake/` app-build and the same includes; do not trust the PR body)
8. Open risks / next action

Name `dgabriner/bakery` PRs by feature. A bare "PR #6" collides with the old SheepMiner baker-units ship. Skip-list ship `cursor/keep-tour-pages-off-live-0a8f` @ `30f591f` is on Staging (Staging `deploy_manifest.ps1` excludes the three tour pages; Repo confirmed Sep 9 and rechecked Oct 8).

## Live claim cliff

Repeated ship watches saw PR text say "Live not touched" / "cloud staging SFTP" while Live app-build or includes still moved without a Next click. Tour pages Staging 200 / Live 404 do **not** clear field 7. Report both hosts. Hold Next while tour/demo pages would copy back.

## Do not

Hold secrets. Force-push. Guess origin. Clone onto this computer. Next-click Live. Drop the tour-page skip patterns from `deploy_manifest.ps1`. Treat "Live not touched" as proven without a host check. Run SFTP yourself. Re-post a full packet for a debounced same-SHA event.
