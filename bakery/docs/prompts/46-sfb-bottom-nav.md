# Prompt 46 — SF Baker bottom navigation

Wave 2 (mobile navigation). `--agent=sfb-bottom-nav`.

---

`includes/sfb_tabs.php` renders eight horizontal tabs that overflow on phones. The customer portal already has the right pattern: four bottom tabs + a More sheet (`includes/portal_nav.php`, `includes/portal_nav.js`).

## Read first

- `includes/sfb_tabs.php`, `includes/sfb_styles.php`, `sfb_dashboard.php`
- `includes/portal_nav.php`, `includes/portal_nav.js`, `includes/portal_styles.php`
- `tests/run_sf_baker_tests.php`, `tests/run_sfb_content_trust_tests.php`

## Ship

Replace the tab strip with bottom tabs **Home / Learn / Bake / Community** + More (Starters, Formulas, Resources, Offerings, account). Reuse portal nav markup/JS with an `sfb` variant; 44px targets; `aria-current` on the active tab.

## Constraints

Origin badges and gating unchanged. No new page. EN + ES.

## Done when

All eight destinations reachable in ≤2 taps at 320px; SFB suites green.

**Status:** shipped 2026-09-04 — Home/Learn/Bake/Community bottom tabs + More sheet; portal_nav.js data-more variant; customer portal tabs suppressed on SFB shell. Staging and Live were not touched.
