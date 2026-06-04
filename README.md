# Topdata PDF Datasheet SW6

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
![Shopware](https://img.shields.io/badge/Shopware-6.7-%2318977B)
![PHP](https://img.shields.io/badge/PHP-8.2+-%23777BB4)

A **Shopware 6.7** plugin that generates beautiful, print-ready **PDF datasheets** for your products on the fly. It uses [Gotenberg](https://gotenberg.dev) — a Docker-based headless Chromium service — to convert Twig-rendered HTML into pixel-perfect PDFs with full CSS support.

> **Why Gotenberg instead of Dompdf?**  
> Gotenberg uses real headless Chromium under the hood, giving you full CSS Grid, Flexbox, `@page` rules, Google Fonts, and modern layout support — none of which are available with legacy PHP-based PDF libraries.

---

## Features

- **SEO-friendly PDF URLs** — `GET /datasheet/{productNumber}/{slug}.pdf`
- **Two built-in themes** — `minimal` (clean, compact) and `focus_shop` (brand corporate design)
- **Configurable margins** — set top/bottom/left/right margins via admin panel
- **PDF download button** — automatically added to the product detail page buy widget
- **HTTP caching** — 24-hour max-age with cache-busting per theme + product
- **Debug mode** — append `?debug=1` to inspect the raw HTML before PDF conversion
- **Base64 image inlining** — images are embedded directly so they render in the isolated Chromium sandbox
- **Multilingual** — English and German storefront snippets included

---

## Requirements

| Dependency     | Version     |
|----------------|-------------|
| Shopware       | 6.7.*       |
| PHP            | 8.2 or higher |
| Docker         | (for Gotenberg) |

---

## Installation

### 1. Install the plugin

```bash
# via Composer (add VCS repository first, then require)
composer config repositories.topdata-pdf-datasheet-sw6 vcs https://github.com/topdata/pdf-datasheet-sw6
composer require topdata/pdf-datasheet-sw6

# or manually: clone/place this repo in custom/plugins/topdata-pdf-datasheet-sw6
```

### 2. Activate the plugin

```bash
bin/console plugin:refresh
bin/console plugin:install --activate TopdataPdfDatasheetSW6
```

### 3. (Re)build the Storefront theme

```bash
bin/console theme:compile
bin/console cache:clear
```

---

## Gotenberg Service

The plugin delegates PDF rendering to a Gotenberg service. You need to run it somewhere reachable from your Shopware instance.

### Quick start (Docker)

```bash
docker run --rm -p 3000:3000 gotenberg/gotenberg:8
```

This starts Gotenberg on `http://localhost:3000`. Update the plugin config if you run it elsewhere.

### Docker Compose

Add a `compose.yaml` (or `docker-compose.yml`) to your project root:

```yaml
services:
  gotenberg:
    image: gotenberg/gotenberg:8
    ports:
      - "3000:3000"
    restart: unless-stopped
    # Optional: resource limits
    # deploy:
    #   resources:
    #     limits:
    #       memory: 512M
```

---

## Configuration

After activation, configure the plugin in the Shopware admin under **Settings → System → Plugins → Topdata Pdf Datasheet SW6**.

| Setting            | Default               | Description                                  |
|--------------------|-----------------------|----------------------------------------------|
| Gotenberg URL      | `http://localhost:3000` | Base URL of your Gotenberg Docker instance    |
| PDF Theme          | `focus_shop`          | Template theme (`minimal` or `focus_shop`)    |
| Cache enabled      | `true`                | Enable HTTP caching for PDF routes            |
| Margin top         | `0.75 in`             | Top margin                                   |
| Margin bottom      | `0.75 in`             | Bottom margin                                |
| Margin left        | `0.5 in`              | Left margin                                  |
| Margin right       | `0.5 in`              | Right margin                                 |

---

## Usage

Once installed and configured, a **PDF Datasheet** button appears on every product detail page (inside the buy widget). Clicking it downloads the PDF for that product.

Direct URL format:

```
https://your-shop.com/datasheet/{productNumber}/{slug}.pdf
```

### Debug mode

Inspect the HTML before PDF conversion:

```
https://your-shop.com/datasheet/{productNumber}/{slug}.pdf?debug=1
```

---

## Themes

### minimal

A clean, high-density datasheet with system fonts, a two-column description/image layout, and a property-group specification table. Uses a monochrome black/white/gray palette.

### focus_shop

A brand-aligned corporate design with Poppins/Roboto fonts, signature Burnt Sienna (`#e96354`) accent color, product badges, description cards, a 2-column spec grid, and a branded footer.

---

## Development

### Project structure

```
topdata-pdf-datasheet-sw6/
├── src/
│   ├── TopdataPdfDatasheetSW6.php        # Plugin entry point
│   ├── Controller/
│   │   └── PdfDatasheetController.php    # Storefront route handler
│   ├── Service/
│   │   └── GotenbergClient.php           # Gotenberg HTTP client
│   ├── Twig/
│   │   └── PdfHelperExtension.php        # Twig filters (base64, slugify)
│   └── Resources/
│       ├── config/config.xml             # Plugin config schema
│       ├── snippet/                      # Translations (en-GB, de-DE)
│       └── views/storefront/             # Twig templates
│           ├── datasheet/                # PDF themes (minimal, focus_shop)
│           └── component/buy-widget/     # Buy-widget button override
└── composer.json
```

### Creating a custom theme

1. Copy `src/Resources/views/storefront/datasheet/minimal.html.twig` as your starting point
2. Use any CSS features — Chromium supports CSS Grid, Flexbox, custom fonts, `@page`, etc.
3. Images must be inlined via the `|pdf_base64_image` Twig filter (the Chromium sandbox has no network access)
4. Register your theme name in `config.xml` under the `pdfTheme` select options
5. To keep your custom header/footer layout aligned with the margins configured in the plugin config, use the injected `margins` variable to style the horizontal padding of your body elements:
   ```css
   body {
       padding-left: {{ margins.marginLeft|default(0.75) }}in;
       padding-right: {{ margins.marginRight|default(0.75) }}in;
   }
   ```

---

## License

This project is licensed under the **MIT License** — see the [LICENSE](LICENSE) file for details.

---

## Authors

- **TopData Software GmbH** — [https://www.topdata.de](https://www.topdata.de)
