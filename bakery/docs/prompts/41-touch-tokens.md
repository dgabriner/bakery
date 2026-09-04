# Prompt 41 — Touch targets and one token set

Wave 2 (mobile navigation). `--agent=touch-tokens`.

---

`css/tokens.css` defines `--sf-touch-min: 44px`, but `css/nav.css` shortcuts are 40px, the portal More button is 36px (`includes/portal_styles.php`), and `includes/sfb_tabs.php` tabs are smaller still. The portal carries a second color system. Fixed layouts still use `100vh` in places where the mobile keyboard fights it.

## Read first

- `css/tokens.css`, `css/base.css`, `css/nav.css`, `css/manager_phone.css`, `css/driver.css`
- `includes/portal_styles.php`, `includes/sfb_styles.php`, `includes/sfb_tabs.php`
- The mobile-shake fix (`includes/client_refresh.js`, `login.php`) — do not reintroduce viewport listeners

## Ship

1. Every interactive element in shared chrome (`nav.css`, portal header/nav, SFB tabs, manager phone, driver) gets `min-height: var(--sf-touch-min)` / `min-width` where it is a square control.
2. Portal accent colors become tokens in `css/tokens.css` (`--sf-portal-*`); `portal_styles.php` references tokens instead of literals. No visual redesign — same values, one source.
3. `100vh` → `100dvh` (with `100vh` fallback) for fixed shells; keep `env(safe-area-inset-*)` where present.
4. New `tests/run_touch_target_tests.php` (register in the work map): string-assert that shared chrome CSS has no `min-height`/`height` below 44px on selectors matching `button|\.btn|a\.|tab|nav__`.

## Constraints

Scope to shared chrome + role shells. Do not restyle the 60 pages with inline CSS (Prompt 50 extracts them first).

## Done when

The touch-target suite is green; a 320px viewport shows no shared control narrower than 44px in DevTools.
