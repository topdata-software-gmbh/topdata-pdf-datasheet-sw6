# AGENTS.md

Topdata Pdf Datasheet SW6 — Shopware 6.7 plugin that generates print-ready PDF datasheets for products via an external Gotenberg (headless Chromium) service.

## What this plugin does

Renders a Twig template per product into HTML, sends it to Gotenberg's `/forms/chromium/convert/html` endpoint, returns a PDF. Exposes one storefront route `GET /datasheet/{productNumber}/{slug}.pdf` (controller: `src/Controller/PdfDatasheetController.php:34`). A "PDF Datasheet" button is injected into the product detail buy-widget via `src/Resources/views/storefront/component/buy-widget/buy-widget-form.html.twig`.

## Architecture (where things live)

- `src/TopdataPdfDatasheetSW6.php` — plugin bootstrap (empty class extending `Shopware\Core\Framework\Plugin`).
- `src/Controller/PdfDatasheetController.php` — storefront controller; resolves theme, reads system config, runs disk cache, renders Twig, delegates to `GotenbergClient`.
- `src/Service/GotenbergClient.php` — Symfony `HttpClient` POST to Gotenberg, builds `multipart/form-data` with `index.html` / `header.html` / `footer.html` file parts.
- `src/Twig/PdfHelperExtension.php` — registers filters `|pdf_base64_image` (reads via `shopware.filesystem.public`, falls back to `file_get_contents` with 5 s timeout) and `|pdf_slugify`.
- `src/Command/CacheClearCommand.php` — `topdata:pdf-datasheet:cache-clear`. **Extends `Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand`** — a soft dependency on the sibling `topdata-foundation-sw6` plugin. If that plugin is not installed the command will fail to load.
- `src/Resources/config/config.xml` — admin UI: `gotenbergUrl`, `pdfTheme` (single-select: `minimal`, `focus_shop`), `cacheEnabled`, `diskCacheEnabled`, `diskCacheTtl`.
- `src/Resources/config/services.xml` — DI: `GotenbergClient` (public), `PdfHelperExtension` (gets `shopware.filesystem.public`), `PdfDatasheetController` (gets `sales_channel.product.repository` + `%kernel.cache_dir%`), `CacheClearCommand` (gets `%kernel.cache_dir%`, tagged `console.command`).
- `src/Resources/config/routes.xml` — auto-imports all `Controller/**/*Controller*.php` via attribute routing. New controllers in `src/Controller/` are picked up automatically; no manual route entry needed.
- `src/Resources/views/storefront/datasheet/{minimal,focus_shop}{,_header,_footer}.html.twig` — six templates (two themes × {body, header, footer}).
- `src/Resources/views/storefront/component/buy-widget/buy-widget-form.html.twig` — `{% sw_extends %}` of the Storefront buy-widget, adds the PDF button.
- `src/Resources/snippet/storefront.{en-GB,de-DE,fr-FR}.json` — snippet namespace `TopdataPdfDatasheetSW6` (`buttonLabel`, `titleText`, `specsTitle`, `manufacturer`, `productNumber`, `description`, `brandClaim`, `page`, `pageOf`).
- `src/Resources/public/pdf-download-focus.svg` — button glyph.

## External runtime dependencies (not in `composer.json`)

1. **Gotenberg** — required at runtime, configured via admin `gotenbergUrl` (default `http://localhost:3000`). Without it the route returns HTTP 500. Recommended: `docker run --rm -p 3000:3000 gotenberg/gotenberg:8`.
2. **TopdataFoundationSW6** — sibling plugin providing `AbstractTopdataCommand` and `CliLogger`. Not declared in `composer.json` `require`; if missing, `CacheClearCommand` will not autoload. Treat as a soft dependency.

## Conventions and gotchas

- **PSR-4 namespace root**: `Topdata\TopdataPdfDatasheetSW6\` → `src/`. Anything outside `src/` will not be autoloaded.
- **Plugin type**: `shopware-platform-plugin`. Plugin class FQCN is registered in `composer.json` `extra.shopware-plugin-class` — must match the namespace/class name or Shopware will refuse to install/activate.
- **Theme is a single-select** in `config.xml`. To add a new theme you must (a) drop three Twig files (`<name>.html.twig`, `<name>_header.html.twig`, `<name>_footer.html.twig`) in `src/Resources/views/storefront/datasheet/`, (b) add an `<option>` in `config.xml` under `pdfTheme`, (c) keep the header/footer template filenames matching the body template name — the controller looks them up by `<theme>` + `_header`/`_footer` suffix (`PdfDatasheetController.php:71-72`).
- **Header/footer Twig failures are swallowed** (`PdfDatasheetController.php:79-97`). A broken header template silently renders an empty header — no exception, no log. Use `?debug=1` to inspect.
- **`@page` margins must match header/footer padding**. Both built-in themes set `@page { margin-top: 25-30mm; margin-bottom: 15-25mm; }` and the header/footer `padding` uses the same horizontal values. Mismatched margins cause overlapping content — see the `_ai/backlog/active/250601_1200__IMPLEMENTATION_PLAN__fix_header_footer_overlapping.md` plan and its follow-up `260301_1200__IMPLEMENTATION_PLAN__pdf_header_footer.md` for the prior fix history.
- **Images must be inlined** with the `|pdf_base64_image` filter. The Chromium sandbox inside Gotenberg has no network access to your Shopware host; `<img src="/media/...">` will fail.
- **Debug mode**: append `?debug=1` to the PDF URL to get raw HTML with `Content-Type: text/html`. Also writes the HTML to `<kernel.cache_dir>/topdata_pdf_datasheet_debug.html`.
- **Disk cache**: enabled per admin config. Files live in `<kernel.cache_dir>/topdata_pdf_datasheet/<md5(productNumber_theme_lang_salesChannel)>.pdf`. TTL is `diskCacheTtl` seconds. Cache is skipped when `?debug=1` is present.
- **HTTP cache**: when `cacheEnabled=true`, the response sets `Cache-Control: public, max-age=86400, s-maxage=86400` and `sw-cache-hash: md5(productNumber . theme)`. Shopware's HTTP cache uses this hash — changing `pdfTheme` for a product effectively busts the HTTP cache too.
- **Product lookup**: `loadProductByNumber` adds associations `media.media`, `cover.media`, `properties.group`, `manufacturer`. Templates can rely on these; do not strip them.
- **Controller is registered `public="true"`** in `services.xml` — needed because it's a storefront controller route target.

## Commands and operations

There are no test, lint, typecheck, or build commands in this repo. Verification happens against a live Shopware 6.7 instance:

- Install plugin: `bin/console plugin:refresh && bin/console plugin:install --activate TopdataPdfDatasheetSW6` (run from the Shopware root, not from this plugin dir).
- Recompile storefront after template/snippet changes: `bin/console theme:compile && bin/console cache:clear`.
- Clear the plugin's disk cache: `bin/console topdata:pdf-datasheet:cache-clear`.
- Start Gotenberg: `docker run --rm -p 3000:3000 gotenberg/gotenberg:8`.

## Snippet namespace

All snippet keys live under `TopdataPdfDatasheetSW6.*`. Templates use `{{ "TopdataPdfDatasheetSW6.<key>"|trans }}`. When adding a key, update all three `storefront.{en-GB,de-DE,fr-FR}.json` files.

## What's intentionally not here

- No `tests/` — directory is empty (only `.gitkeep`). No PHPUnit, no PHPStan, no ECS, no Rector, no CI workflow. Don't invent test commands; if you need tests, set them up from scratch.
- No `vendor/` — composer dependencies are resolved by the host Shopware project, not locally.
- Backlog plans and reports in `_ai/backlog/` are historical; new work uses the standard `_ai/` knowledge-base workflow and creates new plan/report files there.