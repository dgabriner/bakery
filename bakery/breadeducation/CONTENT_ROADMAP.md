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
- **Fixes clinic:** gummy crumb, no oven spring, pale crust, too sour, dense loaf, **starter not rising**, **sticky dough**, **loaf spreads flat**.
- **Starter lab:** starter science, revive a starter, traveling with starter, sourdough myths.
- **More breads:** bagels, sandwich loaf, ciabatta, rye, English muffins.
- **Spanish seed:** `es/` index, masa madre, primer horneado, conchas, glosario.

### Committed but unpublished

- Integrity repair of nested-page assets/metadata (`24ae490`) and this failure-clinic tranche are local on `cursor/breadeducation-static-seo-integrity`. Git push is blocked until canonical GitHub remote ownership is confirmed. Live deploy is a separate owner action.

### Current batch

- Failure clinic 3: starter not rising, sticky dough, loaf spreads flat — **landed this session**.

### Planned next (not started)

- Overproofed vs underproofed (dedicated clinic; currently only a troubleshooting lens + Fresh Loaf thread).
- When is the starter ready to mix (peak window for an established culture).
- Dough temperature at mix.
- Autolyse: when it helps and when to skip.
- Keeping and reheating bread (storage — not the Fresh Loaf debrief page).
- Banneton and proofing setup.

### Rejected / merged into another topic

- Do not spin a second “quiet starter” page: new-week stalls stay on `starter-day-one.html`; fridge neglect stays on `revive-a-starter.html`.
- Do not spin “flat loaf in the oven” as a duplicate of `no-oven-spring.html`. Spreading sideways is `loaf-spreads-flat.html`.
- Do not spin “wet dough handling” as a duplicate of `hydration-by-feel.html`. Sticky-vs-slack diagnosis is `sticky-dough.html`.

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
