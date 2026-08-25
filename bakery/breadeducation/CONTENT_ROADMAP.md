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
- **Starter lab:** starter science, revive a starter, traveling with starter, sourdough myths.
- **More breads:** bagels, sandwich loaf, ciabatta, rye, English muffins.
- **Spanish seed:** `es/` index, masa madre, primer horneado, conchas, glosario.

### Committed but unpublished

- Integrity repair of nested-page assets/metadata (`24ae490`), failure-clinic 3 (`75221f4`), and this proofing-and-readiness tranche are local on `cursor/breadeducation-static-seo-integrity`. Git push is blocked until canonical GitHub remote ownership is confirmed. Live deploy is a separate owner action.

### Current batch

- Proofing and readiness clinic (six pages): overproofed vs underproofed, starter ready to mix, dough temperature, autolyse, banneton and proofing, keeping and reheating — **landed this session**.

### Planned next (not started)

- Preferment and mix decisions: levain vs starter vs discard in the mix; salt timing; mix/fold schedule as a decision page (not a second fermentation essay).
- Crumb and crust clinic remainder: burnt bottom; bursting beside the score (if scoring-patterns does not already own it); ear vs no ear only if still uncovered.
- Flour and water as ingredients: bread vs AP vs whole wheat for a formula; water (chlorine/temp) without fake lab claims; salt percentage in practice.

### Rejected / merged into another topic

- Do not spin a second “quiet starter” page: new-week stalls stay on `starter-day-one.html`; fridge neglect stays on `revive-a-starter.html`.
- Do not spin “flat loaf in the oven” as a duplicate of `no-oven-spring.html`. Spreading sideways is `loaf-spreads-flat.html`.
- Do not spin “wet dough handling” as a duplicate of `hydration-by-feel.html`. Sticky-vs-slack diagnosis is `sticky-dough.html`.
- Do not spin a second overnight-fridge schedule: that is `cold-retard.html`. Basket setup is `banneton-and-proofing.html`.
- Do not spin a second “is a basket required?” shopping answer: that stays on `first-loaf-shopping.html`.
- Do not spin storage as a Fresh Loaf debrief; keeping a baked loaf is `keeping-and-reheating-bread.html`. Autolyse is not a second fermentation essay.

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
- [ ] Deployed via `scripts/push_breadeducation_sftp.ps1 -DryRun` first

## Cadence

One cluster per week is sustainable with the template. Review analytics monthly:
pages whose journey-click rate is low get rewritten before new pages are added.
