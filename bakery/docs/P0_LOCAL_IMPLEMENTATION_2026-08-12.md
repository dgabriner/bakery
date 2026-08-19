# P0 local implementation note — 2026-08-12

This local-only tranche disabled auto-push with
`storage/deploy/.auto_push_disabled`, removed the legacy public baker auto-login,
moved source-managed staff codes to environment-only values, added an
audit-backed five-attempt/15-minute login throttle, denied the listed diagnostic
endpoints in Apache, quarantined the parse-invalid driver trace, and removed
diagnostics from the production manifest.

The new test-target guard checks local mode, `USE_PROD_DB`, configured
host/name, `SELECT DATABASE()`, and PDO connection status before reset or
regression commands run. `scripts/run_local_test_gate.ps1` now performs the
guarded local reset, migrations, lint, and discovered regression suites; the
interactive developer workflow calls it for its test option.

Still human-operated and intentionally not performed: production user/log audit,
credential rotation, a reviewed baseline commit, production preflight, and any
deployment. Auto-push remains disabled and no deploy was attempted.
