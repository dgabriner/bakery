# Production Release — Controlled and Fail-Closed

Production is not an extension of auto-push. Git commits and staging syncs never
change `bakery.sourflour.org/bake` or the live `bakerysf` database.

## Required release chain

1. A clean Git commit is deployed to DreamHost staging.
2. Staging lint and smoke checks pass.
3. The owner completes phone acceptance.
4. `create_release_candidate.ps1` verifies the Git commit and every staged file
   hash, then writes an immutable local candidate manifest.
5. A separate production-promotion mission validates that candidate and obtains
   exact owner authorization.
6. Before any live mutation, that mission must create a verified production
   database backup and a last-known-good code rollback artifact.
7. Only the candidate files and reviewed forward migrations may be applied.
8. Read-only health checks and a release record close the promotion.

Staging data is never copied wholesale to live. Live orders, invoices, users,
and operational events remain authoritative.

## Current safety lock

`scripts/promote_release.ps1` validates and previews a candidate, but live
execution is intentionally disabled. This keeps infrastructure setup and live
promotion as separate authorities. `scripts/push_sftp.ps1` remains a guarded
low-level live transport and is not linked from the normal developer menu,
editor hooks, or staging watcher.

```powershell
.\scripts\promote_release.ps1 -Candidate storage\deploy\releases\candidate_ID.json
```

Any destructive migration, database restore, or live execution requires its
own named approval and rollback plan.
