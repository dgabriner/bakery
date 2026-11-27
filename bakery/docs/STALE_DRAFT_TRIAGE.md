# Stale draft triage, 18 Nov 2026

Drafts #77, #78, #79, #80, #83, and #96 were read on `dgabriner/bakery` and left open. Danny decides whether to rebase, keep, or close each one. This note does not close, merge, or delete any pull request or branch.

- **#77 rebase.** The client-error search still binds a calendar day to a bare midnight, and main has no `app-sha` stamp, so the P0 touch-target fix does not replace this draft. Rebase `cursor/ship-hygiene-gate-c711` onto current main before review.
- **#78 rebase.** The closeout photo split, capture time, GPS flags, and migration 107 are not on main, the branch does not merge cleanly, and P1 is written to salvage this draft. Rebase `cursor/closeout-proof-delivery-6c35` after P0 and drop what main already has.
- **#79 rebase.** The lazy 5 PM survey open and the tomorrow routes page are not on main, and the branch does not merge cleanly. Rebase `cursor/tomorrow-routes-laura-2930` after P0.
- **#80 rebase.** Skip this day is still absent from main. `cursor/skip-dated-day-74c1` merges clean today, and it edits both language files, so rebase it after the P0 language reconcile.
- **#83 keep.** The in-page Android camera (`prefersNativeCamera`) is not on main, and the draft records a Live snapshot of that tip. Leave `cursor/android-photo-memory-5db6` open for Danny. Do not restack a branch that may already be on the host.
- **#96 rebase.** Main already has the staging target guard in `scripts/sftp_upload.py`, and it still has no login preflight or `--diagnose` check before an upload. Rebase `cursor/sftp-login-preflight-0f30` and drop the guard main already has.

Nothing here deploys. Staging was not synced. Live was not touched.
