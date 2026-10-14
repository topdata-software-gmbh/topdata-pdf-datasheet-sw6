---
filename: "_ai/backlog/reports/261014_2030__IMPLEMENTATION_REPORT__webfont_inlining.md"
title: "Report: Web font inlining for PDF datasheets"
createdAt: 2026-10-14 20:30
createdBy: opencode [deepseek-v4.1-flash]
updatedAt: 2026-10-14 21:15
updatedBy: opencode [deepseek-v4.1-flash]
planFile: "_ai/backlog/active/261014_2030__IMPLEMENTATION_PLAN__webfont_inlining.md"
project: "SW6.7 Plugin"
status: completed
filesCreated: 2
filesModified: 3
filesDeleted: 0
tags: [shopware, gotenberg, pdf-datasheet, fonts, performance, resilience]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report - Web font inlining for PDF datasheets

## Summary

The Google Fonts `<link>` in the rendered datasheet HTML is now replaced at render time with a
`<style>` blob whose `@font-face` sources are base64 `data:` URIs. The blob is fetched lazily on
first use (single-flight, atomic write, negative caching) and cached persistently in
`%kernel.project_dir%/var/topdata_pdf_datasheet/webfonts/` — so Chromium inside Gotenberg never
waits on the network: warm renders are deterministic (~0.8 s response, was 8–20 s when Google was
unreachable), and cold-cache failures degrade to system fonts instead of hanging.

Verified live on the `focus` Shopware 6.7.10.2 instance: cold render created a 206,117-byte blob
with 6 inlined woff2 faces and zero remaining `fonts.gstatic.com` URLs; 10 parallel cold requests
produced exactly one fetch; the forced-failure path returned HTTP 200 in 2.4 s with the link
stripped and a `.failed` marker; `?debug=1` still returns the raw template HTML.

## Files Changed

| File | Change |
| --- | --- |
| `src/Service/WebFontInliner.php` | [NEW] fetch, build, cache, single-flight, replace link |
| `src/Controller/PdfDatasheetController.php` | Injected `WebFontInliner`; one `inline()` call after the DB close |
| `src/Resources/config/services.xml` | Service registration (`http_client`, `monolog.logger`, project-dir cache path) |
| `AGENTS.md` | New gotcha bullet documenting behavior and cache location |
| `_ai/backlog/reports/261014_2030__IMPLEMENTATION_REPORT__webfont_inlining.md` | This report |

## Key Changes

- `src/Service/WebFontInliner.php` — public API is a single `inline(string $html): string`.
  It rewrites every matching Google Fonts `<link>` via `preg_replace_callback`; the per-URL work
  (`getFontCss`) is: warm blob → return; recent `.failed` marker → degrade; else
  `flock(LOCK_EX|LOCK_NB)` on `<key>.lock` — the winner fetches CSS + woff2, rewrites every `url()`
  to `data:` URIs, writes the blob atomically (tmp + `rename`), and removes the failure marker;
  losers poll ≤ 3 s (30 × 100 ms with `clearstatcache`) for the blob or the marker, then degrade.
- `src/Controller/PdfDatasheetController.php:117` — inserted
  `$htmlContent = $this->webFontInliner->inline($htmlContent);` between the DB-close guard and the
  Gotenberg `try` block. Header/footer HTML and the `?debug=1` early return are untouched, so the
  raw template HTML (with the original link) is still what debug mode shows.
- `src/Resources/config/services.xml` — registers `WebFontInliner` with
  `$cacheDir = %kernel.project_dir%/var/topdata_pdf_datasheet/webfonts` (persistent base dir,
  survives `cache:clear`).

## Technical Decisions

- **Variant B (cached CSS blob injected into rendered HTML) over a processed Twig template.**
  `StorefrontController::renderView()` resolves templates through Shopware's `TemplateFinder`,
  which only understands logical `@Namespace/…` names; a processed template in a cache dir would
  need a custom Twig loader plus staleness handling. Injecting the blob keeps the render pipeline
  untouched.
- **Cache key `hash('xxh128', $url)`.** A template edit that changes the URL yields a new key and
  triggers a fresh fetch on the next render — fully automatic, no command or deploy step.
- **Chrome UA on the CSS request** so Google returns woff2 subset blocks; only the `latin` subset
  (de/en/fr coverage) is kept — 6 faces, ~205 KB base64 instead of ~1 MB of full TTFs. `SUBSETS`
  is a class constant for a future `latin-ext` addition.
- **`flock` single-flight with bounded waiting.** Exactly one process per URL fetches; concurrent
  renders poll for the finished blob (proven: 10 parallel cold requests → 1 fetch log line).
- **Negative cache (`.failed`, 300 s TTL).** Protects against retry storms while Google is
  unreachable; suppresses refetch attempts, not the per-render warning (the link is still stripped
  and logged on each affected render).
- **Degrade-safe by construction.** Any failure (HTTP != 200, timeout, no `@font-face`, remaining
  `http(s)` `url()`) returns `null` → the link is stripped → system fonts. Rendering never hangs.
- **Order relative to the DB close.** `inline()` runs *after* the DBAL close so the potential
  cold-cache fetch does not hold the shared MariaDB session.

## Testing Notes

Environment: web runs in **dev** mode (`.env.local` sets `APP_ENV=dev`); commands run via
`docker exec focus-www` (Shopware root `/www`). One environment discovery: the web kernel's cache
dir (`var/cache/dev_h8d26…`) differs from the CLI kernel's hash, so `bin/console cache:clear` does
**not** purge the web process' disk PDF cache in this clone — verification had to delete
`var/cache/dev_h8d26…/topdata_pdf_datasheet/*.pdf` directly (as root in the container).

1. **Syntax + container** — `php -l` on both files → no errors; `xmllint` on services.xml → OK;
   `debug:container …WebFontInliner` → arguments `http_client.uri_template`, `monolog.logger`,
   `/www/var/topdata_pdf_datasheet/webfonts`; used by `PdfDatasheetController`.

2. **Cold cache, single request** — `rm -rf …/webfonts` + purge PDF disk cache, then
   `GET /datasheet/5222/oki-toner-kit-cyan-43872307.pdf` → `200` in ~4.5–5.6 s (dev baseline +
   fetch), valid 3-page PDF. Blob `bbea3d27e854d647520186bedb2eb9a0.css` (206,117 bytes) created;
   `grep -c 'data:font/woff2'` → **6**; `grep -c 'https://fonts.gstatic.com'` → **0**. Log:
   `app.INFO: PDF datasheet: fetched and cached web fonts {… key: bbea3d27e854…}` — exactly one line.

3. **Warm path** — repeat request → `200`, PDF byte-identical (`cmp`), blob md5 unchanged (no
   refetch). With the PDF disk cache purged and the blob warm, the render path took ~1.75 s.

4. **Concurrency (single-flight proof)** — cold cache, `seq 1 10 | xargs -P 10 curl` → all ten
   `200` (5.5–9.8 s under 10-way dev load); fetch-log count went 1 → 2 (exactly one new fetch);
   no `.failed` marker.

5. **Failure path** — temporarily pointed the template link at `https://fonts.googleapis.com/css2`
   (verified `400`). Cold request → `200` in 2.39 s (no hang), warning
   `web font inlining failed, stylesheet link stripped {"url":"https://fonts.googleapis.com/css2"}`,
   `.failed` marker present, PDF still rendered. Follow-up request: marker mtime unchanged (2.3 s
   later, no refetch) → negative cache confirmed. Template then restored.

6. **Debug + regressions** — `?debug=1` → `200`, raw HTML, `fonts.googleapis.com` count **1**
   (link kept); `https://focus.docker/` → `200`. Temporary `public/_opcache_probe.php` (diagnostic
   only) was deleted afterwards.

## Documentation Updates

`AGENTS.md` gained the bullet "Web fonts are inlined at render time" describing the `<style>` blob,
the persistent cache path, `flock` serialization, the degrade-to-system-fonts rule, and the
instruction to keep the Google `<link>` in the template and the inliner call in the controller.

## Next Steps

- Bottleneck 3: disk cache default `false` + ephemeral `%kernel.cache_dir%` (WebFontInliner already
  uses the persistent `%kernel.project_dir%/var` base — same direction for the PDF cache).
- Bottleneck 4: concurrency throttling / HTTP 429 for Gotenberg saturation.
- Bottleneck 5: 5 s HTTP fallback per image in `PdfHelperExtension`.
- Optional: add `latin-ext` to `SUBSETS` if Cyrillic/Greek glyphs in product names become an issue;
  add pruning for stale `<key>.css` blobs and `<key>.lock` files.
