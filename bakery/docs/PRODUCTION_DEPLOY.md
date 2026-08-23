# Production Release — Hosted Staging Promotion

Production is not an extension of auto-push. Git commits and staging syncs never
change `bakery.sourflour.org/bake` or the live `bakerysf` database.

## Normal release chain

1. Sync the intended files to DreamHost Staging.
2. Test Staging, including phone acceptance.
3. On Staging Manager, follow the single **Next** action. When Live is behind,
   apply the exact named database migration first; then send the tested files.
   There is no free-form migration selection.
4. Staging creates a private immutable export of the exact tested bytes.
5. The hosted Live workers verify the approval release ID and every source
   hash. A database job verifies its backup before DDL; a file job backs up
   changed files, lints PHP, promotes atomically, and requires an HTTP 200
   health check.
6. A failure after file replacement begins automatically restores the previous
   Live files.
7. Release is complete only when the board reports database **Match** and the
   file worker reports **Succeeded**.

Staging data is never copied wholesale to live. Live orders, invoices, users,
and operational events remain authoritative.

Git remains the history and collaboration system, but Git state is not a
runtime promotion gate. A new commit or unrelated dirty work on the laptop
cannot invalidate an already-approved hosted Staging export.

## Legacy recovery path

The former local candidate/HEAD flow remains recovery tooling while the hosted
path is proven. It is not the normal operator workflow. Local direct-to-Live is
an emergency bypass and still requires explicit owner approval.

`scripts/push_sftp.ps1` remains a guarded low-level live transport. Auto-push
and Sync never call it.

```powershell
.\scripts\create_release_candidate.ps1 -StagingTestedBy "NAME" -ValidateOnly
.\scripts\promote_release.ps1 -Candidate storage\deploy\releases\candidate_ID.json
```

Any destructive migration, database restore, or live execution requires its
own named approval and rollback plan.

See [HOSTED_PROMOTION.md](HOSTED_PROMOTION.md) for hosted components, status,
backup locations, and failure behavior.
