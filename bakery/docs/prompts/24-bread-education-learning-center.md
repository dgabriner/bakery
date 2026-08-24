# Prompt 24 — Bread Education Learning Center (rich content: photos, video, lessons)

Paste this entire file into a **new** agent chat in the `bakery/` workspace. This is stream 2 of 4 in the Community Bread Education Center (`--agent=bread-education`). Do not build the batch builder (Prompt 23), onboarding (Prompt 25), or payments (Prompt 26) here.

Sister prompts: `23-bread-education-batch-builder.md`, `25-home-base-onboarding.md`, `26-education-payments-connect.md`.

---

You are expanding the SF Baker resources tab into a real **learning center**: authored lessons with step-by-step photos and video, progress a learner can see, and a clean bridge to the existing external Bread Education zone (`https://bakery.sourflour.org/breadeducation/`).

## Shared contract

- Stack stays **flat PHP + MariaDB**. No framework rewrite. Domain logic in `includes/sf_baker.php` (+ one new education include if needed); no direct SQL in pages.
- Media precedent is `text_media.php`: files live under gitignored `storage/`, stream **only** through a role-gated PHP page using realpath containment plus nosniff headers. Never serve storage paths directly; never hotlink private media.
- Existing content lives in the library (`includes/sfb_library.php`, canonical/troubleshooting kinds, i18n key-driven cards on `sfb_resources.php`). Extend that model — do not fork a second CMS.
- Content trust rules apply: no invented process claims, no wholesale secrets in public lessons (see `03-content-trust-quality.md`).
- Synthetics never consume lessons or hold progress; only humans have entitlements.
- Safety: tests on `bakerysf_test`; never against `bakerysf_local`. Local/test DB only.
- i18n: every new string in `lang/en.php` and `lang/es.php` under `sfb.*`.

## Read first

- `sfb_resources.php`, `includes/sfb_library.php`, `includes/sfb_library_panel.php`
- `includes/sfb_photo_handler.php` (existing photo handling), `text_media.php` + `includes/text_comms_media.php` (streaming precedent)
- `includes/sf_baker.php` access helpers (`bakery_sfb_require_access`, portal scripts)
- `database/schema/058_text_media.sql` (media columns pattern)
- `BAKERY_PRODUCT_CONTEXT.md` §7 surface rows for sfb surfaces

## Ship

1. **Lesson model**: additive migration (`061+`) for courses → lessons → steps, each step carrying text (i18n keys for seeded copy), optional photo(s), optional self-hosted video file reference, and an order index. Keep runtime-tolerant table checks so old DBs degrade honestly.
2. **Media pipeline**: admin upload lands photos/video under `storage/sfb_media/` (reuse/adapt photo handler validation); streaming page gates by portal/SF Baker role before serving bytes; range requests supported enough for phone video playback.
3. **Lesson player**: step-by-step view with photos/video inline, per-customer progress checkmarks (one small table keyed customer+lesson), and a next-step CTA into the Batch Builder prompt's flow.
4. **Authoring**: admins/managers create/edit courses, lessons, steps, and upload media through simple staff screens — no deploy-time content changes.
5. **Bridge outward**: keep the external Bread Education zone links working; import what belongs in-app as seeded lessons, link out for the rest. One index (`sfb_resources.php` or a new Learn tab wired into `includes/sfb_tabs.php`) — not two competing libraries.
6. **Seed**: port the existing canonical/troubleshooting library pieces into lesson form where they fit; keep their ask-a-question CTAs pointing at community/coach lanes.

## Constraints

- No third-party CDN or embed services; self-hosted files only.
- Storage directory stays gitignored; uploads validated (type/size); traversal impossible.
- Progress and media access are human-only; synthetics excluded everywhere.
- Do not break the existing resources tab URL for anyone with it bookmarked.

## Done when

- An admin authors a course with photos and a video entirely in-app
- A signed-in baker plays a lesson video on a phone, marks steps done, and jumps into a batch
- Direct storage URLs are useless without the app gate (404/redirect)
- en/es complete; new suites green alongside `run_sf_baker_tests.php`
