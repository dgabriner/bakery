# Sour Flour Bread Education

This folder is the deployable `/breadeducation/` learning zone for `bakery.sourflour.org`.

- [`index.html`](index.html) is the learning hub and interactive lab.
- [`fresh-loaf.html`](reference/fresh-loaf.html) is the curated Fresh Loaf reading path, with direct links to the strongest lessons, handbook chapters, forums, bake logs, and troubleshooting threads.
- [`sourdough.html`](sourdough/sourdough.html), [`fermentation.html`](sourdough/fermentation.html), [`formula.html`](technique/formula.html), [`bake.html`](technique/bake.html), [`whole-grain.html`](technique/whole-grain.html), and [`troubleshooting.html`](reference/troubleshooting.html) are focused curriculum pages.
- [`yeasted.html`](technique/yeasted.html) is the supporting commercial-yeast and preferment track.
- The full suite now spans the CONTENT_ROADMAP clusters: beginner path (`first-loaf-shopping.html`, `your-first-dutch-oven-bake.html`, `starter-day-one.html`), technique dives (`scoring-patterns.html`, `shaping-batards.html`, `steam-without-dutch-oven.html`, `cold-retard.html`, `hydration-by-feel.html`), beyond-the-boule (`focaccia.html`, `pizza-at-home.html`, `baguettes.html`, `pretzels.html`, `crackers-and-discard.html`), and bridge/reference (`home-oven-to-market.html`, `baking-glossary-printable.html`). All are carded on the hub grid; beginner pages also ride the pill row.
- Three conversion journeys: [`classes.html`](classes/classes.html) (hands-on workshops), [`sf-baker.html`](journal/sf-baker.html) (free journal account with Sour Flour Hotline access — signup lives at `/bake/customer_login.php?create=1`), and [`find-our-bread.html`](sell/find-our-bread.html) (Noe Valley Farmer's Market, Civic Center, wholesale friends). Every page's nav and footer carries all three.
- Four booking/bridge pages, each with a visible FAQ + matching FAQPage schema: [`corporate-workshops.html`](classes/corporate-workshops.html), [`private-events.html`](classes/private-events.html), [`wholesale.html`](sell/wholesale.html), [`visit-plan.html`](classes/visit-plan.html). They are wired from classes.html (Formats + traveling line), find-our-bread.html (For businesses), and the hub finish-links grid — not the 12-item nav.
- [`TEMPLATE.html`](TEMPLATE.html) is the local-only copy-paste skeleton for new curriculum pages. It is deliberately excluded from the SFTP release list, carries `noindex`, and is served as `410 Gone` if an old deployment still has it. Copy it to the real page path; never link it or add it to the sitemap.
- [`sitemap.xml`](sitemap.xml) lists every page for crawlers and AI answer engines; add each
  new page to it on the day it ships. [`../domain_root/llms.txt`](../domain_root/llms.txt)
  (deployed to `bakery.sourflour.org/llms.txt`) tells AI assistants what Sour Flour is and
  which pages matter — update it when the zone's story changes.
- Every page carries a JSON-LD graph (`Bakery` + `WebSite` + `WebPage` + `BreadcrumbList`;
  `HowTo` where a numbered procedure exists) plus Open Graph mirrors of title/description/
  canonical, and a visible footer "Updated" date. Keep the Bakery/WebSite nodes byte-identical
  across pages; bump `article:modified_time`, the footer date, and the sitemap `lastmod`
  together on every content edit. Only mark up what is visible on the page.
- [`CONTENT_ROADMAP.md`](CONTENT_ROADMAP.md) is the multiplication plan: topic clusters, page checklist, and cadence for becoming the Bay Area's most complete free bread-learning resource.
- [`DEBRIEF.md`](DEBRIEF.md) is the research record behind the Fresh Loaf synthesis, including the source trail and the community/forum design lessons carried into SF Baker.
- `.htaccess` keeps directory access predictable and applies basic browser hardening.

## Adding a page (15 minutes)

1. Copy `TEMPLATE.html` to the real topic folder and new slug (lowercase-hyphen). Its asset and navigation links are root-relative, so preserve the `/breadeducation/...` form. Fill every head TODO marker:
   meta description, canonical slug, og trio, published/modified dates, section-label cues,
   hero promise.
2. Teach in this order: decisions → Fresh Loaf shelf → journey close. Reuse existing
   CSS classes only; do not invent new styles per page.
3. Add a card to the `#curriculum` grid on `index.html` (and the pill row if
   beginner-facing).
4. Add the page to `sitemap.xml` with today's `lastmod`.
5. Check every internal link resolves and the JSON-LD parses (paste into
   validator.schema.org or Rich Results Test). Run the offline static-surface guard, then inspect the upload list before a separately authorized deployment:

```powershell
php tests/run_breadeducation_static_surface_tests.php
.\scripts\push_breadeducation_sftp.ps1 -DryRun   # inspect the file list first
.\scripts\push_breadeducation_sftp.ps1
```
