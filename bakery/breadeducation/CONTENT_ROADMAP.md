# Bread Education — Content Multiplication Roadmap

Goal: make `bakery.sourflour.org/breadeducation/` the most complete free bread-learning
resource in the San Francisco Bay Area, and route every reader toward one of three
journeys: **take a class**, **become an SF Baker** (account → Sour Flour Hotline), or
**find our bread** (Noe Valley Farmer's Market, Civic Center, wholesale friends).

## Positioning

The Fresh Loaf is breadth; we are **place + practice**. Nobody else teaches SF sourdough
from inside a working San Francisco production bakery, and nobody else owns pan dulce.
Every new page should say something only our bench can say.

## Library state (2026-08-24)

Do not treat the cluster list below as unpublished. It is the original multiplication plan.
Current reality lives in `sitemap.xml` plus this status.

**Agent resume:** continue worktree `bakery/tmp/cursor_workers/breadeducation_static_seo_integrity` on branch `cursor/breadeducation-static-seo-integrity`. Local ledger (gitignored): `tmp/seo_forge/SEO_CONTINUITY.md`. Do not mint a parallel SEO branch.

### Published (physical HTML, in sitemap)

- **Hub / conversion:** `index.html`, classes, corporate workshops, private events, visit plan, SF Baker, find-our-bread, wholesale, home-oven-to-market.
- **Cluster 1–5 as originally listed:** all shipped under topic folders (`start/`, `sourdough/`, `technique/`, `breads/`, `pan-dulce/`, `reference/`, `journal/`).
- **Fixes clinic:** gummy crumb, no oven spring, pale crust, too sour, dense loaf, starter not rising, sticky dough, loaf spreads flat, **overproofed vs underproofed**.
- **Proofing and readiness:** starter ready to mix, dough temperature at mix, autolyse, banneton and proofing, keeping and reheating bread.
- **Preferment and mix:** levain vs starter vs discard, how much starter, salt timing, mix and folds, mix order.
- **Flour, water, salt amount, crust hole:** flour for this formula, water for dough, salt percentage, burnt bottom.
- **Starter lab:** starter science, revive a starter, traveling with starter, sourdough myths.
- **More breads:** bagels, sandwich loaf, ciabatta, rye, English muffins.
- **Spanish seed:** `es/` index, masa madre, primer horneado, conchas, glosario.

### Live vs Git

- **Live (2026-08-24):** `scripts/push_breadeducation_sftp.ps1` published 75 files to `https://bakery.sourflour.org/breadeducation/` (not `/bake`). Site-root `/llms.txt` updated from `domain_root/llms.txt`. TEMPLATE.html remains 410. Markdown stays local.
- **Git:** Integrity repair (`24ae490`), failure-clinic 3 (`75221f4`), proofing (`bc883cd`), and preferment/mix (`cf12b75`) are on `cursor/breadeducation-static-seo-integrity`. Git push is blocked until canonical GitHub remote ownership is confirmed. Do not merge into `feat/square-invoicing`.

### Current batch

- Flour, water, salt percentage, and burnt bottom (four pages): uniqueness-checked against shopping, whole-grain ladder, dough-temperature, salt-timing, pale-crust, gummy, scoring — **landed this session**.

### Planned next (not started)

- Workday clock only if dough-temp + cold-retard do not cover schedule.
- Equipment that is not a shopping list and not a clone of `steam-without-dutch-oven.html`.
- Clinic remainder: stuck to the pot; dull vs blistered. Skip ear and bursting — `scoring-patterns.html` already owns those.
- Pan dulce depth; native Spanish for remaining `es/` gaps.

### Rejected / merged into another topic

- Do not spin a second “quiet starter” page: new-week stalls stay on `starter-day-one.html`; fridge neglect stays on `revive-a-starter.html`.
- Do not spin “flat loaf in the oven” as a duplicate of `no-oven-spring.html`. Spreading sideways is `loaf-spreads-flat.html`.
- Do not spin “wet dough handling” as a duplicate of `hydration-by-feel.html`. Sticky-vs-slack diagnosis is `sticky-dough.html`.
- Do not spin a second overnight-fridge schedule: that is `cold-retard.html`. Basket setup is `banneton-and-proofing.html`.
- Do not spin a second “is a basket required?” shopping answer: that stays on `first-loaf-shopping.html`.
- Do not spin storage as a Fresh Loaf debrief; keeping a baked loaf is `keeping-and-reheating-bread.html`. Autolyse is not a second fermentation essay.
- Do not spin a second poolish/biga page; that stays on `yeasted.html`. Knead-vs-fold lives inside `mix-and-folds.html`, not a sixth mix page.
- Do not spin a second salt-amount page; that is `salt-percentage.html`. `salt-timing.html` stays when salt arrives.
- Do not spin a second flour shopping page; substitutions inside a written formula are `flour-for-this-formula.html`. The blend ladder stays on `whole-grain.html`.
- Do not spin water temperature as a quality essay; that is `dough-temperature.html`. Tap vs filter vs chlorine is `water-for-dough.html`.
- Do not spin a second pale-crust or gummy page for a dark base; that is `burnt-bottom.html`.

## Topic clusters (in build order)

### Cluster 1 — SF authority (differentiators)
1. `sf-sourdough.html` — what "San Francisco sourdough" actually means; our starters,
   our fog, our flour choices. The page competitors cannot copy.
2. `pan-dulce.html` — conchas, orejas, cuernos, picon: enriched doughs, lamination-lite,
   topping science. Near-zero quality competition in English. Links to whole-grain and
   yeasted pages for enrichment fundamentals.

### Cluster 2 — First-loaf expansion (beginner capture)
3. `first-loaf-shopping.html` — exactly what to buy: flours by budget, scale/thermometer
   picks at three price points, banneton vs bowl-and-towel.
4. `your-first-dutch-oven-bake.html` — one canonical recipe walked end-to-end with photos
   slots and a printable card.
5. `starter-day-one.html` — day-by-day culture creation with troubleshooting callouts per day.

### Cluster 3 — Technique deep dives (authority + long-tail search)
6. `scoring-patterns.html`, `shaping-batards.html`, `steam-without-dutch-oven.html`,
   `cold-retard.html`, `hydration-by-feel.html`.

### Cluster 4 — Beyond the boule (breadth)
7. `focaccia.html`, `pizza-at-home.html`, `baguettes.html`, `pretzels.html`,
   `crackers-and-discard.html` (starter discard = retention loop back to starter pages).

### Cluster 5 — Bridge pages (conversion)
8. `home-oven-to-market.html` — how class → journal → market table connect; strong
   classes.html CTA.
9. `baking-glossary-printable.html` — print-friendly reference that earns links.

## Page anatomy (enforced by TEMPLATE.html)

hero (promise) → teach sections (step-list/cards/table from education-pages.css) →
Fresh Loaf shelf (2–4 curated external links) → journey close (one card each:
classes / sf-baker / find-our-bread). No new CSS without a farm discussion.

## Checklist for every new page

- [ ] Copied TEMPLATE.html; slug set in filename AND canonical link AND og:url AND both @id URLs in the JSON-LD graph
- [ ] Title pattern `Topic A· Sour Flour Bread Education`; unique meta description; og:title/og:description/og:url mirror title/description/canonical exactly
- [ ] article:published_time set to ship date; footer "Updated" date + sitemap lastmod match
- [ ] JSON-LD parses (validator.schema.org); Bakery/WebSite nodes byte-identical to other pages
- [ ] Standard 12-item nav block unchanged
- [ ] Journey-close section present with all three links
- [ ] All internal hrefs resolve; external links use `target="_blank" rel="noopener"`
- [ ] Facts checked against DEBRIEF.md research record; nothing copied verbatim
- [ ] Added to hub `#curriculum` grid (and pill row if beginner-facing)
- [ ] Added to sitemap.xml with today's lastmod
- [x] Deployed via `scripts/push_breadeducation_sftp.ps1 -DryRun` first, then live SFTP to `/breadeducation/` (2026-08-24)

## Cadence

One cluster per week is sustainable with the template. Review analytics monthly:
pages whose journey-click rate is low get rewritten before new pages are added.
