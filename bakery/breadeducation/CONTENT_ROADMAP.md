# Bread Education — Content Multiplication Roadmap

Goal: make `bakery.sourflour.org/breadeducation/` the most complete free bread-learning
resource in the San Francisco Bay Area, and route every reader toward one of three
journeys: **take a class**, **become an SF Baker** (account → Sour Flour Hotline), or
**find our bread** (Noe Valley Farmer's Market, Civic Center, wholesale friends).

## Positioning

The Fresh Loaf is breadth; we are **place + practice**. Nobody else teaches SF sourdough
from inside a working San Francisco production bakery, and nobody else owns pan dulce.
Every new page should say something only our bench can say.

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
