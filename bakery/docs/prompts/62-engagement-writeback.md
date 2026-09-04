# Prompt 62 — Engagement writes back into ops

Wave 4 (integration). `--agent=engagement-writeback`.

---

Two engagement loops end in a human retyping: (a) driver survey store-verify / route-order results are applied on Driver Assignment by hand; (b) a failed stop's recovery case records "customer notified" as a field, while the actual text is sent from a different screen and the credit is re-entered in Billing Center.

## Read first

- `includes/survey_store_verify.php`, `includes/surveys.php`, `survey.php`, `driver_assignment.php` + `includes/driver_assignments.php`
- `includes/delivery_recovery.php`, `docs/FAILED_STOP_RECOVERY_MODEL.md`, `includes/exception_desk.php`
- `text_comms.php`, `includes/text_comms.php` (send path), `billing_center.php`
- Suites: `run_survey_store_verify_tests.php`, `run_survey_route_order_tests.php`, `run_failed_stop_recovery_tests.php`, `run_text_comms_tests.php`

## Ship

1. Survey → Driver Assignment: an "Apply to route" action on the survey result that calls the existing assignment helpers (reorder / lock store) for that dated route, with a diff preview and one confirm; records an `operational_events` row.
2. Failed stop → text: the recovery card offers "Text customer" using `bakery_text_comms_send` with a templated EN/ES message; the ledger row links to the recovery case. Sending still happens only through the Command Center send path.
3. Failed stop → credit: "Create credit" writes a `customer_delivery_issues` row pre-filled from the case so Billing Center sees it in its existing queue (no new amounts, no invoice mutation from the desk).

## Constraints

Failed-stop rules are law (reason + note; retry only from failed; reassign via Driver Assignment). No second write path for texts or credits.

## Done when

Both loops close in one tap from the screen where the decision happens; the four suites plus `run_exception_desk_tests.php` are green.

**Status:** shipped 2026-09-04 — Apply to route (preview + confirm + `operational_events`) on survey results; recovery Text customer via `bakery_text_send` (`context_type=recovery`); Create credit via `bakery_delivery_issue_submit_from_recovery` into Service Issues. Staging and Live were not touched.
