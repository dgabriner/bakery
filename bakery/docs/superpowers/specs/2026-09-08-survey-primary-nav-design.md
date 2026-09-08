# Route survey in primary nav — design

**Approved:** 2026-09-08 (cloud agent; owner request: primary nav for drivers, today/tomorrow, Laura/manager sees everyone)

## Goal

Drivers reach lock-stores + set-order surveys from **primary nav** (not buried in More). On the survey hub they can flip **Today ↔ Tomorrow**. Managers (Laura) open the **all-drivers** HQ hub from the same survey entry.

## Scope

| Surface | Change |
|--------|--------|
| **Driver primary nav** | Add **Survey** as a direct tab; remove duplicate from More. |
| **Survey hub** (`survey.php`, no token) | Include app chrome (header + nav). Resolve `?date=`. Prominent **Today** / **Tomorrow** chips. Drivers mint own links; managers always mint HQ (`driver_id=0`). |
| **Manager More** | First everyday shortcut becomes **Route survey** → `survey.php` (everyone). Survey Center stays in All tools + hub link. |
| **Token survey date bar** | Same Today / Tomorrow chips beside the date control. |

## Out of scope

- New pages or modules
- Changing store-verify / route-order save semantics
- SMS / mint behavior in Survey Center beyond navigation

## Acceptance

1. Driver bottom bar shows Survey as a primary item; active on `survey.php`.
2. Hub opens for today or tomorrow in one tap.
3. Manager session on `survey.php` shows all-drivers lock + order cards (not a single linked driver).
4. EN + ES strings; navigation + survey contract tests updated.
