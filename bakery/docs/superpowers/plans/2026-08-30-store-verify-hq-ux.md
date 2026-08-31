# Store-verify HQ UX Implementation Plan

> **For agentic workers:** Implement task-by-task on `cursor/survey-store-verify-5d10`. No force-push. Staging SFTP after green tests.

**Goal:** Collapsible drivers, zone grouping, optional move-to-driver mode, date switching, and copyable Manager/driver links on existing `survey.php`.

**Architecture:** Pure helpers in `includes/survey_store_verify.php`; render/JS in `survey.php`; dual-write lang keys; extend `tests/run_survey_store_verify_tests.php`.

**Tech Stack:** PHP 8, flat survey page CSS/JS, no new modules.

## Global Constraints

- Close loop on `survey.php` only; no new top-level page.
- Survey-only reassign (no assignment DB writes).
- Staging only for prove; never Live Next.
- Additive git; i18n en+es.

---

### Task 1: Helpers + tests

- [ ] Zone group helper + date resolve helper + store rows include `zone`
- [ ] Extend pure tests; run suite

### Task 2: survey.php UX

- [ ] Date control, collapsible HQ drivers, zone sections, move mode JS, links strip
- [ ] `view_driver` scopes HQ token to one driver list
- [ ] Lang keys en+es

### Task 3: Ship prove

- [ ] php -l + tests; commit; push; SFTP Staging; remint/share links
