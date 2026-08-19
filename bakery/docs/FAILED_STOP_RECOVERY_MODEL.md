# Failed-stop recovery model

## Purpose and ownership

A failed stop is a dated delivery exception, not a replacement order or a billing
decision. `daily_order_assignments.delivery_status = 'failed'` remains the
authoritative record that the original attempt did not complete. The recovery
case supplements that record with manager decisions; it does not overwrite
delivery quantities, invoices, credits, or customer communications.

`delivery_recovery_cases` owns the manager workflow for one failed assignment.
`operational_events` is the append-only audit trail for every report, decision,
retry, reassignment, communication update, billing handoff, and completion.
Driver Assignment remains the only route-assignment mutation surface.

## State model

| State | Meaning | Allowed next decisions |
| --- | --- | --- |
| `open` | Driver or manager reported a failed attempt with a reason. | Acknowledge, plan retry, reassign, resolve. |
| `acknowledged` | A manager owns the assessment. | Plan retry, reassign, resolve. |
| `retry_scheduled` | The same driver has a recorded future retry time; the assignment has returned to `pending`. | Reassign, resolve, or record a new failure. |
| `reassigned` | The failed assignment is retained in the case history and the active stop was moved through Driver Assignment. | Resolve, or record a new failure on the replacement assignment. |
| `resolved` | The operational outcome and manager resolution note are recorded. | Close. |
| `closed` | Final manager completion state. | No direct transition; reopen requires a new failed stop. |

An assignment may be retried only from `failed`, only after a reason and manager
note exist, and only with a future retry time. Reassignment is allowed only from
`failed` or `retry_scheduled`, only to an active driver, and it must call the
existing Driver Assignment transfer service. A delivered or in-transit stop is
never reassigned by this workflow.

## Required fields and handoffs

- Failure reason code: `recipient_unavailable`, `access_issue`,
  `unsafe_conditions`, `vehicle_issue`, `product_issue`, `customer_request`,
  `payment_issue`, or `other`. `other` requires a manager note.
- Manager note: required when reporting a failure, scheduling a retry,
  reassigning, resolving, or closing. It describes the decision, not a customer
  secret or payment-card data.
- Customer communication status: `not_needed`, `pending`, `contacted`, or
  `unable_to_reach`. A note records the channel/outcome; this is a status log,
  not an automatic message sender.
- Billing/credit handoff: `not_needed`, `review_needed`, `credit_requested`,
  `credit_issued`, or `not_billable`. It is deliberately a handoff only. The
  Billing Center remains the source of truth for invoice and credit mutations.

## Manager exception work queue

The queue persists only manager coordination fields against a stable exception
key: acknowledgement timestamp/user, assigned manager, due time, resolution
note, and completion timestamp/user. Existing exception type, category,
severity, and deep link continue to come from the command-center exception
contract. Completing a queue item never suppresses or changes its underlying
exception; it simply records that the manager disposition is complete.

## Audit requirements

Each workflow decision writes an `operational_events` row containing the case,
assignment, dated order, actor, prior/current recovery state, and safe action
metadata. Reassignment records both source and destination driver IDs. The
original failed assignment ID remains on the case even when Driver Assignment
creates the new active assignment.

## Safety rules

- All manager mutations require an administrator or manager session and CSRF.
- The operating date is validated before any write.
- Resolution/close cannot occur without a manager resolution note.
- Failed-stop metadata must not be used to mark an invoice paid, issue a credit,
  or alter delivered quantities.
