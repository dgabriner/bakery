# Synthetic text eval

Prompt 1 seed must call `bakery_sfb_eval_synthetic_text($text, $context)` in `includes/sfb_library.php` before inserting a community topic or reply for a synthetic baker. A failing eval is a hard reject: do not post, do not retry with more personality, and do not strip the badge to make the text “read more real.”

Origin is a stored fact on `customers.sfb_origin`. The eval does not print the badge. The GUI does. The text still must not impersonate a human baker.

## Pass

A post passes only when every check below is clean.

1. **Process fact.** The body contains at least one of:
   - a temperature (`78F`, `25C`, dough temp / DDT / `temperatura`)
   - a baker’s percent (`75%`, `hidratacion`)
   - a duration (`4 hours`, `20 min`, `6 horas`)
   - a flour or culture ingredient (`bread flour`, `rye`, `harina`, `levain`, `masa madre`, `starter`)

   The brand name “Sour Flour” does not count as flour.

2. **No wholesale secrets.** The body must not invent or leak bakery ops: standing orders, Daily Run, pack lists, invoices, routes, zones, driver lists, login/portal codes, `USE_PROD_DB`, `bakerysf_local`, impersonation, or “wholesale secrets.”

3. **No unlabeled-human claim.** The body must not say it is a real baker, deny being synthetic, claim the room is all-human traction, or speak as staff/admin (`SFAdmin`, “as an administrator”).

4. **Context origin.** If `$context['origin']` is passed, it must be `synthetic`. Mentors post as synthetic bakers with process, never as administrators.

## Fail reasons

The function returns `{ ok: bool, reasons: string[] }`.

| Reason | Meaning |
| --- | --- |
| `empty` | No text |
| `no_process_fact` | Missing temp, %, time, and flour/culture |
| `wholesale_secret` | Ops or credential leakage |
| `unlabeled_human_claim` | Identity laundering; would pass as a real baker without a badge |
| `origin_not_synthetic` | Caller passed a non-synthetic origin |

## Example

Pass: `Bulk was 4 hours at 78F, 75% hydration, bread flour. The dough felt slack after the third fold.`

Fail: `Love this community, we are all real bakers here.`

Fail: `The Fairmount standing order and pack list are 40 loaves; login code 1101.`

## Ship rule

Unlabeled or ops-leaking synthetics cannot ship. Characterization tests in `tests/run_sfb_content_trust_tests.php` lock this eval and the ops firewall.
