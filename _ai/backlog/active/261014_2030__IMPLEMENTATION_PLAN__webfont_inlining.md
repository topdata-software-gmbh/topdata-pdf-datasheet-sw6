---
filename: "_ai/backlog/active/261014_2030__IMPLEMENTATION_PLAN__webfont_inlining.md"
title: "Inline Google web fonts into PDF datasheets with fully automatic lazy caching (single-flight, atomic, degrade-safe)"
createdAt: 2026-10-14 20:30
createdBy: opencode [deepseek-v4.1-flash]
updatedAt: 2026-10-14 20:49
updatedBy: opencode [deepseek-v4.1-flash]
status: draft
priority: medium
tags: [shopware, gotenberg, pdf-datasheet, fonts, performance, resilience]
project: "SW6.7 Plugin"
estimatedComplexity: moderate
documentRevision: 2
documentType: IMPLEMENTATION_PLAN
---

# Implementation Plan - Web font inlining for PDF datasheets

## 1. Problem Statement

`focus_shop.html.twig` line 6 loads Google Fonts via an external `<link>`:

```html
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
```

Every PDF render makes Chromium inside Gotenberg fetch the stylesheet and font files from Google
before it can finish the page — per-render external work (DNS + TLS + CSS + up to 6 woff2 downloads)
and a hard runtime dependency. Live systems have full internet access, so this normally goes
unnoticed; if Google is ever slow or unreachable (outage, rate limit, firewall/DNS change), Chromium
blocks on the stylesheet until it gives up. That failure mode was reproduced on the local clone
during a crawl on 14/Oct/2026 (13:26–13:29), where the network had no egress:

- render latencies of 6.42 s / 8.12 s / 9.54 s in the Gotenberg access log,
- two `20.098s → 503 context deadline exceeded` (API timeout),
- a `500` response for `/fr/datasheet/4952/…` on the storefront side,
- MariaDB `Too many connections` once enough waiting requests held their connections.

Measured on the local clone (warm Gotenberg):

| Test HTML | Stylesheet unreachable | Stylesheet reachable |
| --- | --- | --- |
| Plain page | 0.25–0.27 s | 0.28 s |
| + Google Fonts `<link>` | **8.27 s** (DNS hang) | 0.29–0.31 s |
| 6 latin faces inlined as base64 | 0.29 s | 0.28 s |

The goal: keep the Google `<link>` in the template source, but fetch it **once** (lazily, fully
automatically) and inline the fonts as base64, so every render is deterministic and network-free —
regardless of Google's availability or latency:

- On every render, find the Google Fonts link in the rendered HTML.
- Look up a cached, base64-inlined CSS blob keyed by `hash('xxh128', $url)` on disk.
- On miss: **one** request per URL fetches the CSS + woff2 files (single-flight via `flock`), rewrites
  every `url()` to a `data:` URI, and stores the blob atomically.
- On failure (Google unreachable, cold cache): strip the `<link>` and render with system fonts — never hang.
- Fully automatic: no command, no deploy step, no admin setting. A template edit that changes the URL
  is picked up on the next render (new hash → refetch).

## 2. Implementation Notes

### Environment (verified)

- Shopware root: `/topdata/clones/focus/vol/www` — Shopware **6.7.10.2**, PHP **8.4** (Apache prefork + mod_php).
- `hash('xxh128', …)` available (verified in `focus-www`: `bbea3d27e854d647520186bedb2eb9a0` for the current URL).
- `Psr\Log\LoggerInterface` is a valid autowirable service (`vendor/shopware/core/Framework/DependencyInjection/cache.xml:52`).
- Only `focus_shop.html.twig` contains the font link; `minimal*.twig` and all `*_header/_footer` templates have none.
- Live systems always have egress; the local clone did not at measurement time. Rendering must not depend on it.

### Why this variant (not a processed Twig template)

`StorefrontController::renderView()` resolves templates through Shopware's `TemplateFinder`
(`StorefrontController.php:287`, `TemplateFinder.php:51`), which only understands logical
`@Namespace/…` names. Rendering a processed template from a cache dir would need `createTemplate()`
or a Twig loader path plus staleness handling. Variant B (cached CSS blob injected into the rendered
HTML) keeps the whole render pipeline untouched.

### Google Fonts API quirks (verified)

| Fetch with | CSS response | Base64 total |
| --- | --- | --- |
| no UA / PHP default | 6 full **TTF** files | 1,076 KB |
| Chrome UA | 36 woff2 **subset** blocks → keep `latin` | **205 KB** (latin), 335 KB (latin + latin-ext) |

Both render identically (0.28 s vs 0.32 s; Chromium subsets when embedding into the PDF). The UA header
is a size optimization; `latin` covers de/en/fr glyphs. `SUBSETS` is a class constant so `latin-ext`
can be added later.

### Cache location

`%kernel.project_dir%/var/topdata_pdf_datasheet/webfonts/` — persistent (survives `cache:clear`),
writable, and the same base dir direction as bottleneck 3. Not `%kernel.cache_dir%`.

### Ordering with bottleneck 1

The DB close guard (`261014_1923__IMPLEMENTATION_PLAN__close_db_connection_before_gotenberg.md`)
must run **before** `WebFontInliner::inline()`: on a cold cache the inliner can block up to ~1–3 s.
Final order: `renderView` → `?debug` return → DB close → `inline()` → `convertHtml()`.

### Relevant files

| File | Change |
| --- | --- |
| `src/Service/WebFontInliner.php` | [NEW] fetch, build, cache, single-flight, replace link |
| `src/Controller/PdfDatasheetController.php` | [MODIFY] constructor + one call before Gotenberg |
| `src/Resources/config/services.xml` | [MODIFY] register service with cache dir argument |
| `AGENTS.md` | [MODIFY] document behavior and cache dir |
| `_ai/backlog/reports/…` | [NEW] implementation report |

### Commands (from `/topdata/clones/focus/vol/www`)

```bash
php -l custom/plugins/topdata-pdf-datasheet-sw6/src/Service/WebFontInliner.php
php -l custom/plugins/topdata-pdf-datasheet-sw6/src/Controller/PdfDatasheetController.php
bin/console cache:clear
rm -rf var/topdata_pdf_datasheet/webfonts                       # simulate cold cache
curl -k -s -o /tmp/ds.pdf -w '%{http_code} %{time_total}\n' \
  'https://focus.docker/datasheet/5222/oki-toner-kit-cyan-43872307.pdf'
```

## 3. Implementation Phases

### Phase 1: WebFontInliner service

**Objective:** one class that turns a Google Fonts `<link>` into a `<style>` blob, lazily and safely.

#### [NEW FILE] `src/Service/WebFontInliner.php`

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataPdfDatasheetSW6\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WebFontInliner
{
    private const CHROME_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';

    private const SUBSETS = ['latin'];

    private const FETCH_TIMEOUT = 5.0;

    private const FAILURE_MARKER_TTL = 300;

    private const WAIT_POLLS = 30;

    private const WAIT_POLL_INTERVAL_US = 100_000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $cacheDir
    ) {}

    public function inline(string $html): string
    {
        return (string) preg_replace_callback(
            '#<link[^>]+href="(https://fonts\.googleapis\.com/css2[^"]*)"[^>]*>#i',
            function (array $matches): string {
                $css = $this->getFontCss(html_entity_decode($matches[1], ENT_QUOTES));

                if ($css === null) {
                    $this->logger->warning('PDF datasheet: web font inlining failed, stylesheet link stripped', [
                        'url' => $matches[1],
                    ]);

                    return '';
                }

                return '<style>' . $css . '</style>';
            },
            $html
        );
    }

    private function getFontCss(string $url): ?string
    {
        $key = hash('xxh128', $url);
        $cssFile = $this->cacheDir . '/' . $key . '.css';
        $lockFile = $this->cacheDir . '/' . $key . '.lock';
        $failureFile = $this->cacheDir . '/' . $key . '.failed';

        if ($this->isValidBlob($cssFile)) {
            return (string) file_get_contents($cssFile);
        }

        if (is_file($failureFile) && (time() - (int) filemtime($failureFile)) < self::FAILURE_MARKER_TTL) {
            return null;
        }

        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            return null;
        }

        $lockHandle = @fopen($lockFile, 'c');

        if ($lockHandle === false) {
            return null;
        }

        if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
            try {
                $css = $this->fetchAndBuild($url);

                if ($css === null) {
                    @touch($failureFile);

                    return null;
                }

                if (!$this->writeAtomically($cssFile, $css)) {
                    $this->logger->warning('PDF datasheet: could not write web font cache file', ['file' => $cssFile]);
                }

                @unlink($failureFile);
                $this->logger->info('PDF datasheet: fetched and cached web fonts', ['url' => $url, 'key' => $key]);

                return $css;
            } finally {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }

        fclose($lockHandle);

        for ($i = 0; $i < self::WAIT_POLLS; ++$i) {
            usleep(self::WAIT_POLL_INTERVAL_US);
            clearstatcache(true, $cssFile);

            if ($this->isValidBlob($cssFile)) {
                return (string) file_get_contents($cssFile);
            }

            if (is_file($failureFile)) {
                return null;
            }
        }

        return null;
    }

    private function fetchAndBuild(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => self::CHROME_UA],
                'timeout' => self::FETCH_TIMEOUT,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $css = $response->getContent();
        } catch (\Throwable) {
            return null;
        }

        $css = $this->inlineFontFiles($css);

        if ($css === null || $css === '' || !str_contains($css, '@font-face')) {
            return null;
        }

        return $css;
    }

    private function inlineFontFiles(string $css): ?string
    {
        if (!preg_match_all('~/\*\s*([\w-]+)\s*\*/\s*@font-face\s*\{([^}]*)\}~s', $css, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $result = '';

        foreach ($matches as $match) {
            if (!in_array($match[1], self::SUBSETS, true)) {
                continue;
            }

            $block = $match[2];

            if (!preg_match_all('~url\(([\'"]?)(https://fonts\.gstatic\.com/[^)\'"]+)\1\)~', $block, $urlMatches)) {
                continue;
            }

            foreach ($urlMatches[2] as $fontUrl) {
                $dataUri = $this->fetchFontDataUri($fontUrl);

                if ($dataUri === null) {
                    return null;
                }

                $block = (string) preg_replace(
                    '~url\(([\'"]?)' . preg_quote($fontUrl, '~') . '\1\)~',
                    'url(' . $dataUri . ')',
                    $block
                );
            }

            $result .= '@font-face {' . $block . "}\n";
        }

        if ($result === '' || preg_match('~url\(\s*[\'"]?https?://~', $result)) {
            return null;
        }

        return $result;
    }

    private function fetchFontDataUri(string $fontUrl): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $fontUrl, ['timeout' => self::FETCH_TIMEOUT]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $binary = $response->getContent();
        } catch (\Throwable) {
            return null;
        }

        return 'data:font/woff2;base64,' . base64_encode($binary);
    }

    private function isValidBlob(string $file): bool
    {
        if (!is_file($file) || filesize($file) === 0) {
            return false;
        }

        return str_contains((string) file_get_contents($file), '@font-face');
    }

    private function writeAtomically(string $file, string $content): bool
    {
        $tmp = $file . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, $content) === false) {
            return false;
        }

        return @rename($tmp, $file);
    }
}
```

**Design guarantees:**

- **Single-flight**: `flock(LOCK_EX | LOCK_NB)` on `<key>.lock` — exactly one request per URL fetches.
- **Atomic**: temp file + `rename()`; readers never see a partial blob. The lock file is separate from
  the target because `rename()` swaps the inode.
- **Bounded waiting**: concurrent requests poll ≤ 3 s (30 × 100 ms, with `clearstatcache`) for the blob
  or the failure marker, then degrade by stripping the link.
- **Negative cache**: `.failed` marker suppresses refetch attempts for 300 s (protects against retry
  storms while Google is unreachable).
- **No external requests possible**: every `url()` is rewritten to a `data:` URI and the result is
  rejected if any `http(s)` `url()` remains.
- **Degrade-safe**: any failure returns `null` → the link is stripped → template CSS falls back to
  `sans-serif`. Rendering never hangs.
- **Fully automatic**: cache key is `hash('xxh128', $url)`; a template edit with a new URL produces a
  new key and triggers a fresh fetch on the next render.

### Phase 2: Controller integration

**Objective:** call the inliner on the rendered body HTML only, after the debug branch and the DB close.

#### [MODIFY] `src/Controller/PdfDatasheetController.php`

Add the import and constructor parameter:

```php
use Topdata\TopdataPdfDatasheetSW6\Service\WebFontInliner;
```

```php
    public function __construct(
        private readonly SalesChannelRepository $productRepository,
        private readonly GotenbergClient $gotenbergClient,
        private readonly SystemConfigService $systemConfigService,
        private readonly WebFontInliner $webFontInliner,
        private readonly Connection $connection,
        private readonly string $cacheDir
    ) {}
```

Insert between the `?debug` early return and the `try { … convertHtml … }` block (order matters —
see "Ordering with bottleneck 1"):

```php
        if ($this->connection->isConnected() && $this->connection->getTransactionNestingLevel() === 0) {
            $this->connection->close();
        }

        $htmlContent = $this->webFontInliner->inline($htmlContent);

        try {
            $pdfContent = $this->gotenbergClient->convertHtml($gotenbergUrl, $htmlContent, $headerHtml, $footerHtml);
        } catch (\Throwable $e) {
            return new Response($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
```

Notes:

- Header/footer HTML is untouched (no font links there).
- `?debug=1` keeps returning the **raw** rendered HTML (no 205 KB blob in the debug file); the inliner
  only runs on the production path.
- If the bottleneck-1 close block is not implemented yet, omit those three lines — the inliner still
  works (it would just hold the DB connection during a cold-cache fetch).

### Phase 3: DI wiring

#### [MODIFY] `src/Resources/config/services.xml`

```xml
        <service id="Topdata\TopdataPdfDatasheetSW6\Service\WebFontInliner">
            <argument type="service" key="$httpClient" id="Symfony\Contracts\HttpClient\HttpClientInterface"/>
            <argument type="service" key="$logger" id="Psr\Log\LoggerInterface"/>
            <argument type="string" key="$cacheDir">%kernel.project_dir%/var/topdata_pdf_datasheet/webfonts</argument>
        </service>
```

### Phase 4: Documentation

#### [MODIFY] `AGENTS.md` (plugin root)

Add under "Conventions and gotchas":

> **Web fonts are inlined at render time.** `WebFontInliner` replaces the Google Fonts `<link>` in the
> rendered datasheet HTML with a `<style>` blob whose `@font-face` sources are base64 `data:` URIs.
> The blob is fetched lazily on first use and cached in
> `%kernel.project_dir%/var/topdata_pdf_datasheet/webfonts/<xxh128(url)>.css` (persistent, not wiped by
> `cache:clear`). Concurrent cold-cache requests are serialized with `flock`; on fetch failure the link
> is stripped and system fonts are used (never let Chromium wait on the network — an unreachable
> stylesheet was reproduced locally as 8–20 s render hangs, 503s, and MariaDB connection pile-up).
> Keep the Google `<link>` in the template; do not remove the inliner call.

### Phase 5: Verification

Run from `/topdata/clones/focus/vol/www` unless noted.

1. **Syntax + container**
   ```bash
   php -l custom/plugins/topdata-pdf-datasheet-sw6/src/Service/WebFontInliner.php
   php -l custom/plugins/topdata-pdf-datasheet-sw6/src/Controller/PdfDatasheetController.php
   bin/console cache:clear
   ```
2. **Cold cache, single request**
   ```bash
   rm -rf var/topdata_pdf_datasheet/webfonts
   curl -k -s -o /tmp/ds.pdf -w '%{http_code} %{time_total}\n' \
     'https://focus.docker/datasheet/5222/oki-toner-kit-cyan-43872307.pdf'
   file /tmp/ds.pdf                                       # PDF document
   ls var/topdata_pdf_datasheet/webfonts/                 # <xxh128>.css
   grep -c 'data:font/woff2' var/topdata_pdf_datasheet/webfonts/*.css   # 6
   grep -c 'https://fonts.gstatic.com' var/topdata_pdf_datasheet/webfonts/*.css  # 0
   ```
   First request ≈ 1 s (fetch); second request ≈ 0.3 s with no new log line.
3. **Concurrency (single-flight proof)** — cold cache, 10 parallel requests:
   ```bash
   rm -rf var/topdata_pdf_datasheet/webfonts
   seq 1 10 | xargs -P 10 -I{} curl -k -s -o /dev/null -w '%{http_code} %{time_total}\n' \
     'https://focus.docker/datasheet/5222/oki-toner-kit-cyan-43872307.pdf'
   grep -c 'fetched and cached web fonts' var/log/*.log   # exactly 1 for this window
   ls var/topdata_pdf_datasheet/webfonts/*.failed 2>/dev/null  # none
   ```
   All requests 200; exactly one fetch log line; waiters return after the blob lands.
4. **Failure path (temporary template edit)** — point the link at an invalid family so the CSS API
   fails (restore the template afterwards and re-run `cache:clear` if Twig cached it):
   ```bash
   # edit focus_shop.html.twig: family=Poppins → family=ThisFontDoesNotExist12345
   rm -rf var/topdata_pdf_datasheet/webfonts
   curl -k -s -o /tmp/ds-fail.pdf -w '%{http_code} %{time_total}\n' \
     'https://focus.docker/datasheet/5222/oki-toner-kit-cyan-43872307.pdf'
   # expect: 200, < 1s (no hang), warning in var/log, .failed marker present
   ```
5. **Debug + regressions**
   ```bash
   curl -k -s 'https://focus.docker/datasheet/5222/oki-toner-kit-cyan-43872307.pdf?debug=1' | grep -c 'fonts.googleapis.com'  # 1 (raw link kept)
   curl -k -s -o /dev/null -w '%{http_code}\n' 'https://focus.docker/'   # storefront unaffected
   ```

### Phase 6: Implementation report

#### [NEW FILE] `_ai/backlog/reports/261014_2030__IMPLEMENTATION_REPORT__webfont_inlining.md`

```yaml
---
filename: "_ai/backlog/reports/261014_2030__IMPLEMENTATION_REPORT__webfont_inlining.md"
title: "Report: Web font inlining for PDF datasheets"
createdAt: 2026-10-14 20:30
createdBy: opencode [deepseek-v4.1-flash]
updatedAt: 2026-10-14 20:30
updatedBy: opencode [deepseek-v4.1-flash]
planFile: "_ai/backlog/active/261014_2030__IMPLEMENTATION_PLAN__webfont_inlining.md"
project: "SW6.7 Plugin"
status: completed
filesCreated: 1
filesModified: 3
filesDeleted: 0
tags: [shopware, gotenberg, pdf-datasheet, fonts, performance, resilience]
documentType: IMPLEMENTATION_REPORT
---
```

Report body: Summary, Files Changed, Key Changes, Technical Decisions (Variant B over processed
template; xxh128 cache key; flock single-flight; negative cache; degrade-to-system-fonts), Testing
Notes (Phase 5 commands with observed outputs), Documentation Updates, Next Steps.

## 4. Edge Cases and Risks

| Case | Behavior |
| --- | --- |
| Concurrent cold start (crawler storm) | One fetcher; others poll ≤ 3 s then use the blob |
| Google unreachable, cold cache | `.failed` marker, link stripped, warning logged; request finishes in < 1 s |
| Google unreachable, warm cache | Cache hit; zero impact |
| Retry storm during outage | `.failed` marker blocks refetch attempts for 300 s |
| Corrupt/empty blob on disk | `isValidBlob()` fails → treated as miss → refetch |
| Template edit changes font URL | New xxh128 key → automatic refetch on next render |
| Multiple Google Fonts links | Regex callback handles each URL independently |
| `&amp;` in href | `html_entity_decode` before fetching |
| Glyphs outside `latin` (e.g. Cyrillic product names) | System-font fallback; add `latin-ext` to `SUBSETS` if needed |
| Disk full / write failure | Warning logged; current request still renders; next request refetches |
| `var/` shared over NFS | `flock` may be unreliable → switch to Redis/Symfony lock (out of scope) |
| Old blobs after URL change | Harmless garbage; optional pruning or `cache-clear` cleanup (out of scope) |
| `?debug=1` | Raw template HTML, no inlining |

## 5. Out of Scope

- Warm-up console command (not needed — the design is fully automatic).
- Pruning stale `<key>.css` blobs.
- Bottlenecks 3 (persistent PDF cache) and 4 (Gotenberg concurrency throttle).
- Making `SUBSETS` / UA configurable via admin config.
