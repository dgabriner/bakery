# Prompt 34 — One error boundary

Wave 1 (reliability). `--agent=error-boundary`.

---

There is no global exception handler; uncaught errors become blank 500s or, with `BAKERY_SHOW_ERRORS=1`, a fatal message with file paths in the browser. Pages echo raw `$e->getMessage()` (SQL text, schema names) into HTML and JSON. `safe_execute()` swallows failures and returns `false`, so CRUD callers can report "saved" after a failed write.

## Read first

- `includes/config.php` (DEBUG_MODE, `app_log`), `includes/production_errors.php`, `includes/database.php` (`safe_execute`)
- `includes/common_functions.php` (`generate_crud_handlers`)
- `customers.php`, `daily_orders.php`, `production_center.php`, `complete_delivery.php` — the `catch` blocks
- `includes/auth.php` (`bakery_wants_json`)

## Ship

1. `includes/error_boundary.php`, required from `config.php` after DEBUG_MODE: `set_exception_handler`, `set_error_handler` (warnings → log), shutdown handler for fatals. Non-local: log to `logs/error.log` with a short `error_id`, render `{"success":false,"error":"internal","error_id":...}` for JSON requests or a plain bilingual page otherwise. Local: keep the full trace.
2. `BAKERY_SHOW_ERRORS` only honored when `IS_LOCAL`.
3. `bakery_error_message_for_user(Throwable $e): string` — returns the message for `RuntimeException`/`InvalidArgumentException` raised by our own helpers (they are written for users), logs and returns a generic key for `PDOException` and everything else. Use it in the four pages' catch blocks.
4. `safe_execute()` rethrows in local, and in production logs + throws a `RuntimeException('database_write_failed')` so callers cannot treat `false` as success. Update `generate_crud_handlers` accordingly.
5. i18n keys for the generic messages in `lang/en.php` + `lang/es.php`.

## Tests

New `tests/run_error_boundary_tests.php` (register in the work map): PDO message never reaches the user string; `RuntimeException` from a helper does; JSON shape on error; `safe_execute` throws. Plus `run_i18n_tests.php`, `run_integrity_tests.php`.

## Done when

A forced PDO error on `daily_orders.php` in non-local mode shows a generic message with an `error_id`, and `logs/error.log` has the detail.
