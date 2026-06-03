---
filename: "_ai/backlog/active/260301_0900__IMPLEMENTATION_PLAN__gotenberg_pdf_datasheet.md"
title: "Fresh Gotenberg-based PDF Datasheet with SEO URLs and Focus Shop Brand Design"
createdAt: 2026-03-01 09:00
updatedAt: 2026-03-01 09:00
status: in-progress
priority: high
tags: [shopware, gotenberg, pdf-generation, seo-urls, focus-shop, php]
estimatedComplexity: complex
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Statement
The legacy 3rd-party Shopware PDF datasheet plugin relied on Dompdf, a PHP-based PDF rendering library with outdated and incomplete CSS support (lacking modern CSS Grid, Flexbox, proper font embedding, and CSS3 attributes). In addition, Dompdf execution within the main PHP process frequently led to memory limit exhaustion on products with extensive variants. Finally, the legacy plugin did not provide SEO-friendly URLs or brand-aligned corporate design templates matching the new "Focus Shop" brand standards.

## 2. Executive Summary
This implementation plan provides a fresh, high-performance, and lightweight Shopware 6.7 plugin from scratch. It delegates the PDF compilation load to a dedicated **Gotenberg** service, utilizing headless Chromium to render PDF pages with full support for CSS Grid, Flexbox, custom modern typography, and standard print stylesheets.

The solution includes:
* **Gotenberg API Client**: An autowired, robust Symfony service that builds and dispatches the HTML payload, optional custom margins, and header/footer configurations to Gotenberg.
* **Two Visual Themes**:
  1. `minimal`: A clean, high-density baseline grid format.
  2. `focus_shop`: A brand-aligned corporate design implementation using **Poppins** for headings, **Roboto** for body text, the **Burnt Sienna** (`#e96354`) primary brand accent color, and the **Nevada** (`#494e50`) secondary dark gray slate color.
* **SEO-Friendly "Pretty" URLs**: Routing paths defined as `/datasheet/{productNumber}/{slug}.pdf` utilizing an automated slugifier to generate human-readable, indexable paths dynamically.
* **Base64 Image Inlining**: Automatic on-the-fly conversion of product media URLs into Base64 data URIs to ensure flawless asset loading in isolated Gotenberg runtime environments.

---

## 3. Project Environment Details
* **Project Name**: SW6.7 Gotenberg PDF Datasheet Plugin
* **Backend Root**: `src`
* **PHP Version**: `8.2 / 8.3 / 8.4`
* **PDF Service Engine**: Gotenberg (External docker service, typically running on `http://localhost:3000`)

---

## 4. Phased Implementation Roadmap

### Phase 1: Configuration, Core Cleanup & Service Registration
* Delete legacy boilerplate controllers and commands.
* Design the plugin configuration interface in `config.xml` to allow system administrators to configure the Gotenberg endpoint, select themes (`minimal` vs. `focus_shop`), toggle caching, and set default PDF layouts.
* Configure modern Symfony dependency injection and autowiring within `services.xml`.

### Phase 2: Gotenberg Integration & Twig Helpers
* Implement `GotenbergClient` using Symfony's `HttpClient` to communicate with Gotenberg's `/forms/chromium/convert/html` API endpoint.
* Implement custom Twig extensions to safely slugify product titles on-the-fly to guarantee beautiful URLs, and convert media images into Base64 strings to safeguard asset rendering.

### Phase 3: The Rendering Controller & Pretty SEO Routing
* Create `PdfDatasheetController` with a storefront route resolving `/datasheet/{productNumber}/{slug}.pdf`.
* Integrate product inheritance, loading properties, custom media, and fallbacks.
* Dynamically generate files and cache headers.

### Phase 4: Theme Design & Twig Templates
* Build the `minimal` layout focusing on clean structured table data.
* Build the `focus_shop` layout featuring modern styling, custom typography via web fonts, rounded corner layout structures, brand-specific color arrays, and structured product specification cards.
* Override the product detail `buy-widget` to place the download link with direct SEO configuration.

### Phase 5: Verification & Report Compilation
* Write a full implementation report detailing verified endpoints, visual layouts, and configuration structures.

---

## 5. Implementation Source Code Changes

### [DELETE] Remove Legacy Boilerplate Code
The original boilerplate files from the generator will be removed to keep the workspace clean and unpolluted.

* `src/Command/ExampleCommand.php`
* `src/Controller/AdminApiExampleController.php`
* `src/Controller/StorefrontExampleController.php`
* `src/Resources/views/storefront/example.html.twig`

---

### [NEW FILE] `src/Resources/config/config.xml`
Defines the admin configuration fields for the plugin, including Gotenberg service address, theme choices, and page setup options.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/platform/trunk/src/Core/System/SystemConfig/Schema/config.xsd">
    <card>
        <title>Gotenberg Service Configuration</title>
        <title lang="de-DE">Gotenberg Service-Konfiguration</title>

        <input-field type="text">
            <name>gotenbergUrl</name>
            <label>Gotenberg Service URL</label>
            <label lang="de-DE">Gotenberg Service-URL</label>
            <defaultValue>http://localhost:3000</defaultValue>
            <helpText>The base URL of the running Gotenberg Docker instance (e.g., http://gotenberg:3000).</helpText>
            <helpText lang="de-DE">Die Basis-URL der laufenden Gotenberg-Docker-Instanz (z.B. http://gotenberg:3000).</helpText>
        </input-field>

        <input-field type="single-select">
            <name>pdfTheme</name>
            <label>PDF Template Theme</label>
            <label lang="de-DE">PDF-Template-Design</label>
            <defaultValue>focus_shop</defaultValue>
            <options>
                <option>
                    <id>minimal</id>
                    <name>Minimalistic Grid</name>
                    <name lang="de-DE">Minimalistisch</name>
                </option>
                <option>
                    <id>focus_shop</id>
                    <name>Focus Shop Brand Corporate Design</name>
                    <name lang="de-DE">Focus Shop Corporate Design</name>
                </option>
            </options>
        </input-field>

        <input-field type="bool">
            <name>cacheEnabled</name>
            <label>Enable HTTP Caching for PDF Routes</label>
            <label lang="de-DE">HTTP-Caching für PDF-Routen aktivieren</label>
            <defaultValue>true</defaultValue>
        </input-field>
    </card>

    <card>
        <title>PDF Document Setup</title>
        <title lang="de-DE">PDF-Dokumenteneinstellungen</title>

        <input-field type="float">
            <name>marginTop</name>
            <label>Top Margin (inches)</label>
            <label lang="de-DE">Oberer Rand (Zoll)</label>
            <defaultValue>0.75</defaultValue>
        </input-field>

        <input-field type="float">
            <name>marginBottom</name>
            <label>Bottom Margin (inches)</label>
            <label lang="de-DE">Unterer Rand (Zoll)</label>
            <defaultValue>0.75</defaultValue>
        </input-field>

        <input-field type="float">
            <name>marginLeft</name>
            <label>Left Margin (inches)</label>
            <label lang="de-DE">Linker Rand (Zoll)</label>
            <defaultValue>0.5</defaultValue>
        </input-field>

        <input-field type="float">
            <name>marginRight</name>
            <label>Right Margin (inches)</label>
            <label lang="de-DE">Rechter Rand (Zoll)</label>
            <defaultValue>0.5</defaultValue>
        </input-field>
    </card>
</config>
```

---

### [MODIFY] `src/Resources/config/services.xml`
Register all new backend services, including our custom Twig extensions, Gotenberg API Client, and the routing controller with standard Symfony autowiring.

```xml
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>
        <defaults autowire="true" autoconfigure="true" public="false"/>

        <!-- Gotenberg Service Integration -->
        <service id="Topdata\TopdataPdfDatasheetSW6\Service\GotenbergClient" public="true"/>

        <!-- Custom Twig Utilities -->
        <service id="Topdata\TopdataPdfDatasheetSW6\Twig\PdfHelperExtension">
            <tag name="twig.extension"/>
        </service>

        <!-- Main PDF Generator Controller -->
        <service id="Topdata\TopdataPdfDatasheetSW6\Controller\PdfDatasheetController" public="true">
            <argument type="service" key="$productRepository" id="sales_channel.product.repository"/>
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>
    </services>
</container>
```

---

### [NEW FILE] `src/Service/GotenbergClient.php`
Encapsulates API execution logic with Gotenberg. Uses modern PHP constructor promotion and the Symfony `HttpClient` module.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataPdfDatasheetSW6\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\Response;

class GotenbergClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {}

    /**
     * Converts raw HTML content to a PDF binary stream using Gotenberg Chromium engine.
     */
    public function convertHtml(
        string $gotenbergUrl,
        string $htmlContent,
        array $margins = []
    ): string {
        $endpoint = rtrim($gotenbergUrl, '/') . '/forms/chromium/convert/html';

        $boundary = '---------------------------' . uniqid('', true);
        $headers = [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ];

        // Format body for Gotenberg multipart form requirements
        $body = $this->buildMultipartBody($boundary, $htmlContent, $margins);

        $response = $this->httpClient->request('POST', $endpoint, [
            'headers' => $headers,
            'body' => $body,
        ]);

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            throw new \RuntimeException(sprintf(
                'Gotenberg PDF generation failed with status code: %d. Error details: %s',
                $response->getStatusCode(),
                $response->getContent(false)
            ));
        }

        return $response->getContent();
    }

    private function buildMultipartBody(string $boundary, string $htmlContent, array $margins): string
    {
        $body = '';

        // Add index.html file element
        $body .= "--" . $boundary . "\r\n";
        $body .= "Content-Disposition: form-data; name=\"files\"; filename=\"index.html\"\r\n";
        $body .= "Content-Type: text/html\r\n\r\n";
        $body .= $htmlContent . "\r\n";

        // Append sizing margins as simple string fields
        foreach ($margins as $key => $val) {
            $body .= "--" . $boundary . "\r\n";
            $body .= "Content-Disposition: form-data; name=\"" . $key . "\"\r\n\r\n";
            $body .= $val . "\r\n";
        }

        $body .= "--" . $boundary . "--\r\n";

        return $body;
    }
}
```

---

### [NEW FILE] `src/Twig/PdfHelperExtension.php`
This class implements Twig extensions.
1. `pdf_base64_image`: Safely converts local public image files or accessible network image URLs into inline Base64 strings, keeping image assets safe inside Gotenberg.
2. `pdf_slugify`: Slugifies product names to yield clean, predictable string sequences for SEO URLs.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataPdfDatasheetSW6\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class PdfHelperExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('pdf_base64_image', [$this, 'base64Image']),
            new TwigFilter('pdf_slugify', [$this, 'slugify']),
        ];
    }

    /**
     * Converts a file path or URL to an inline base64 image data URI.
     */
    public function base64Image(?string $path): string
    {
        if (empty($path)) {
            return '';
        }

        try {
            // Attempt to fetch file from web directory if it's relative
            if (!str_starts_with($path, 'http://') && !str_starts_with($path, 'https://')) {
                $absolutePath = public_path($path);
                if (file_exists($absolutePath)) {
                    $data = file_get_contents($absolutePath);
                    $type = mime_content_type($absolutePath) ?: 'image/png';
                    return 'data:' . $type . ';base64,' . base64_encode($data);
                }
            }

            // Otherwise, retrieve absolute URL safely with a short timeout
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'ignore_errors' => true
                ]
            ]);

            $data = @file_get_contents($path, false, $context);
            if ($data === false || empty($data)) {
                return '';
            }

            // Grab mime type from headers if possible, default to jpeg/png
            $headers = $http_response_header ?? [];
            $mimeType = 'image/png';
            foreach ($headers as $header) {
                if (stripos($header, 'Content-Type:') === 0) {
                    $mimeType = trim(substr($header, 13));
                    break;
                }
            }

            return 'data:' . $mimeType . ';base64,' . base64_encode($data);
        } catch (\Throwable $e) {
            // Return empty fallback silently to keep rendering stable
            return '';
        }
    }

    /**
     * Generates clean SEO slug from a product title string.
     */
    public function slugify(string $text): string
    {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text);
        $text = strtolower($text);

        return empty($text) ? 'product' : $text;
    }
}
```

---

### [NEW FILE] `src/Controller/PdfDatasheetController.php`
Resolves SEO route inputs, verifies active product channels, matches parameters, fetches content through configured Twig files, and calls Gotenberg.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataPdfDatasheetSW6\Controller;

use Shopware\Core\Content\Product\Exception\ProductNotFoundException;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Topdata\TopdataPdfDatasheetSW6\Service\GotenbergClient;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class PdfDatasheetController extends StorefrontController
{
    public function __construct(
        private readonly SalesChannelRepository $productRepository,
        private readonly GotenbergClient $gotenbergClient,
        private readonly SystemConfigService $systemConfigService
    ) {}

    #[Route(
        path: '/datasheet/{productNumber}/{slug}.pdf',
        name: 'frontend.pdf_datasheet.get',
        defaults: ['_httpCache' => true],
        methods: ['GET']
    )]
    public function renderPdf(
        string $productNumber,
        string $slug,
        SalesChannelContext $context,
        Request $request
    ): Response {
        $salesChannelId = $context->getSalesChannelId();
        
        // Load active sales channel product criteria
        $product = $this->loadProductByNumber($productNumber, $context);
        if (!$product) {
            throw new ProductNotFoundException($productNumber);
        }

        // Fetch configurations
        $gotenbergUrl = $this->systemConfigService->getString('TopdataPdfDatasheetSW6.config.gotenbergUrl', $salesChannelId);
        $theme = $this->systemConfigService->getString('TopdataPdfDatasheetSW6.config.pdfTheme', $salesChannelId) ?: 'focus_shop';
        $cacheEnabled = $this->systemConfigService->getBool('TopdataPdfDatasheetSW6.config.cacheEnabled', $salesChannelId);

        if (empty($gotenbergUrl)) {
            return new Response('Gotenberg Service URL is not configured.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $margins = [
            'marginTop' => $this->systemConfigService->getFloat('TopdataPdfDatasheetSW6.config.marginTop', $salesChannelId) ?: 0.75,
            'marginBottom' => $this->systemConfigService->getFloat('TopdataPdfDatasheetSW6.config.marginBottom', $salesChannelId) ?: 0.75,
            'marginLeft' => $this->systemConfigService->getFloat('TopdataPdfDatasheetSW6.config.marginLeft', $salesChannelId) ?: 0.5,
            'marginRight' => $this->systemConfigService->getFloat('TopdataPdfDatasheetSW6.config.marginRight', $salesChannelId) ?: 0.5,
        ];

        // Compile HTML via Twig template matching current theme selection
        $templatePath = sprintf('@TopdataPdfDatasheetSW6/storefront/datasheet/%s.html.twig', $theme);
        
        $htmlContent = $this->renderView($templatePath, [
            'product' => $product,
            'context' => $context
        ]);

        // Debug output option to allow quick template inspection via web inspector
        if ($request->query->has('debug')) {
            return new Response($htmlContent, Response::HTTP_OK, ['Content-Type' => 'text/html']);
        }

        try {
            $pdfContent = $this->gotenbergClient->convertHtml($gotenbergUrl, $htmlContent, $margins);
        } catch (\Throwable $e) {
            return new Response($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Build elegant binary stream disposition
        $cleanFilename = sprintf('datasheet-%s.pdf', $productNumber);
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            $cleanFilename
        );

        $response = new Response($pdfContent);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', $disposition);

        if ($cacheEnabled) {
            $response->headers->set('Cache-Control', 'public, max-age=86400, s-maxage=86400');
            $response->headers->set('sw-cache-hash', md5($productNumber . $theme));
        } else {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        }

        return $response;
    }

    private function loadProductByNumber(string $productNumber, SalesChannelContext $context): ?SalesChannelProductEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('productNumber', $productNumber));
        $criteria->addAssociation('media');
        $criteria->addAssociation('cover');
        $criteria->addAssociation('properties.group');
        $criteria->addAssociation('manufacturer');
        $criteria->setLimit(1);

        return $this->productRepository->search($criteria, $context)->first();
    }
}
```

---

### [NEW FILE] `src/Resources/snippet/storefront.de-DE.json`
German translations for frontend templates, avoiding nesting subdirectories for snippets in compliance with project standards.

```json
{
    "TopdataPdfDatasheetSW6": {
        "buttonLabel": "PDF-Datenblatt",
        "titleText": "Technische Produktdaten",
        "specsTitle": "Spezifikationen",
        "manufacturer": "Hersteller",
        "productNumber": "Artikelnummer",
        "description": "Produktbeschreibung",
        "brandClaim": "einfach persönlicher"
    }
}
```

---

### [NEW FILE] `src/Resources/snippet/storefront.en-GB.json`
English translations for frontend templates.

```json
{
    "TopdataPdfDatasheetSW6": {
        "buttonLabel": "PDF Datasheet",
        "titleText": "Technical Product Details",
        "specsTitle": "Specifications",
        "manufacturer": "Manufacturer",
        "productNumber": "Product number",
        "description": "Product Description",
        "brandClaim": "simply more personal"
    }
}
```

---

### [NEW FILE] `src/Resources/views/storefront/datasheet/minimal.html.twig`
Minimalistic layout built entirely using classic grid patterns, perfect for clean high-density technical prints.

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ product.translated.name }} - Datasheet</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 13px;
            color: #333333;
            line-height: 1.5;
            margin: 0;
            padding: 20px;
        }
        .header {
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            margin-bottom: 30px;
        }
        .title {
            font-size: 24px;
            font-weight: bold;
            margin: 0 0 5px 0;
            color: #111;
        }
        .meta {
            font-size: 12px;
            color: #666;
        }
        .grid {
            display: flex;
            gap: 40px;
            margin-bottom: 30px;
        }
        .col-main {
            flex: 2;
        }
        .col-side {
            flex: 1;
            text-align: right;
        }
        .product-image {
            max-width: 100%;
            max-height: 250px;
            object-fit: contain;
            border: 1px solid #eee;
            padding: 10px;
        }
        .section-title {
            font-size: 16px;
            font-weight: bold;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
            margin: 25px 0 15px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .specs-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .specs-table th, .specs-table td {
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .specs-table th {
            font-weight: 600;
            width: 35%;
            color: #555;
        }
    </style>
</head>
<body>

    <div class="header">
        <div class="title">{{ product.translated.name }}</div>
        <div class="meta">
            {{ "TopdataPdfDatasheetSW6.productNumber"|trans }}: {{ product.productNumber }}
            {% if product.manufacturer %}
                | {{ "TopdataPdfDatasheetSW6.manufacturer"|trans }}: {{ product.manufacturer.translated.name }}
            {% endif %}
        </div>
    </div>

    <div class="grid">
        <div class="col-main">
            <div class="section-title">{{ "TopdataPdfDatasheetSW6.description"|trans }}</div>
            <div class="description-content">
                {{ product.translated.description|raw }}
            </div>
        </div>
        <div class="col-side">
            {% if product.cover %}
                <img class="product-image" src="{{ product.cover.media.url|pdf_base64_image }}" alt="Product Image">
            {% endif %}
        </div>
    </div>

    {% if product.properties.count > 0 %}
        <div class="section-title">{{ "TopdataPdfDatasheetSW6.specsTitle"|trans }}</div>
        <table class="specs-table">
            <tbody>
                {% for property in product.properties %}
                    <tr>
                        <th>{{ property.group.translated.name }}</th>
                        <td>{{ property.translated.name }}</td>
                    </tr>
                {% endfor %}
            </tbody>
        </table>
    {% endif %}

</body>
</html>
```

---

### [NEW FILE] `src/Resources/views/storefront/datasheet/focus_shop.html.twig`
The core focus shop brand layout designed using **Poppins** headings, **Roboto** body typeface, **Burnt Sienna** accents (`#e96354`), and signature visual attributes.

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ product.translated.name }}</title>
    <!-- Import brand identity Google fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        @page {
            size: A4;
            margin: 0;
        }
        body {
            font-family: 'Roboto', sans-serif;
            font-size: 14px;
            color: #494e50; /* Brand Nevada Secondary */
            line-height: 1.6;
            margin: 0;
            padding: 40px;
            background-color: #ffffff;
        }
        h1, h2, h3, h4 {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            color: #3c3e40; /* Brand Abbey Dark Gray */
            margin-top: 0;
        }
        .container {
            max-width: 100%;
            margin: 0 auto;
        }
        /* Top Brand Strip Header */
        .brand-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-bottom: 3px solid #e96354; /* Brand Burnt Sienna Primary */
            padding-bottom: 15px;
            margin-bottom: 35px;
        }
        .logo-text {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            font-size: 32px;
            color: #e96354;
            line-height: 1;
        }
        .logo-text span {
            color: #494e50;
        }
        .brand-claim {
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            font-style: italic;
            font-size: 14px;
            color: #828384; /* Brand Oslo Gray */
        }
        /* Page Grid layout */
        .flex-row {
            display: flex;
            gap: 40px;
            margin-bottom: 40px;
        }
        .flex-col-left {
            flex: 1.3;
        }
        .flex-col-right {
            flex: 0.7;
            text-align: center;
        }
        /* Styled Accent Card for Title & Base specs */
        .product-badge {
            display: inline-block;
            background-color: #e96354;
            color: #ffffff;
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 11px;
            padding: 4px 12px;
            border-radius: 12px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .product-title {
            font-size: 28px;
            font-weight: 700;
            color: #3c3e40;
            line-height: 1.2;
            margin-bottom: 10px;
        }
        .meta-text {
            font-size: 13px;
            color: #828384;
            margin-bottom: 25px;
        }
        .image-card {
            background-color: #ffffff;
            border: 2px solid #c7c8c8; /* Brand Tiara */
            border-radius: 8px;
            padding: 15px;
            display: inline-block;
            max-width: 100%;
        }
        .product-image {
            max-width: 100%;
            max-height: 280px;
            object-fit: contain;
        }
        .section-heading {
            font-size: 18px;
            color: #3c3e40;
            border-bottom: 2px solid #c7c8c8;
            padding-bottom: 6px;
            margin-top: 30px;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        /* Table of Specifications */
        .spec-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 15px;
        }
        .spec-card {
            background-color: #ffffff;
            border-left: 3px solid #e96354;
            border-top: 1px solid #c7c8c8;
            border-right: 1px solid #c7c8c8;
            border-bottom: 1px solid #c7c8c8;
            padding: 10px 15px;
            border-radius: 0 4px 4px 0;
        }
        .spec-label {
            font-family: 'Poppins', sans-serif;
            font-size: 12px;
            color: #828384;
            text-transform: uppercase;
        }
        .spec-value {
            font-size: 14px;
            font-weight: 500;
            color: #3c3e40;
        }
        /* Page Footer styling */
        .brand-footer {
            margin-top: 50px;
            border-top: 1px solid #c7c8c8;
            padding-top: 15px;
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #828384;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="brand-header">
        <div class="logo-text">focus<span>shop</span></div>
        <div class="brand-claim">{{ "TopdataPdfDatasheetSW6.brandClaim"|trans }}</div>
    </div>

    <div class="flex-row">
        <div class="flex-col-left">
            <span class="product-badge">{{ "TopdataPdfDatasheetSW6.titleText"|trans }}</span>
            <h1 class="product-title">{{ product.translated.name }}</h1>
            <div class="meta-text">
                <strong>{{ "TopdataPdfDatasheetSW6.productNumber"|trans }}:</strong> {{ product.productNumber }}
                {% if product.manufacturer %}
                    | <strong>{{ "TopdataPdfDatasheetSW6.manufacturer"|trans }}:</strong> {{ product.manufacturer.translated.name }}
                {% endif %}
            </div>

            <h2 class="section-heading">{{ "TopdataPdfDatasheetSW6.description"|trans }}</h2>
            <div class="description-text">
                {{ product.translated.description|raw }}
            </div>
        </div>
        <div class="flex-col-right">
            {% if product.cover %}
                <div class="image-card">
                    <img class="product-image" src="{{ product.cover.media.url|pdf_base64_image }}" alt="Product Cover Image">
                </div>
            {% endif %}
        </div>
    </div>

    {% if product.properties.count > 0 %}
        <h2 class="section-heading">{{ "TopdataPdfDatasheetSW6.specsTitle"|trans }}</h2>
        <div class="spec-grid">
            {% for property in product.properties %}
                <div class="spec-card">
                    <div class="spec-label">{{ property.group.translated.name }}</div>
                    <div class="spec-value">{{ property.translated.name }}</div>
                </div>
            {% endfor %}
        </div>
    {% endif %}

    <div class="brand-footer">
        <div>&copy; {{ "now"|date("Y") }} Focus Shop. All rights reserved.</div>
        <div>{{ "TopdataPdfDatasheetSW6.brandClaim"|trans }}</div>
    </div>
</div>

</body>
</html>
```

---

### [NEW FILE] `src/Resources/views/storefront/component/buy-widget/buy-widget-form.html.twig`
Extends the storefront buy container, adding a beautiful button referencing the pretty slug route.

```twig
{% sw_extends '@Storefront/storefront/component/buy-widget/buy-widget-form.html.twig' %}

{% block buy_widget_buy_container %}
    {{ parent() }}

    {% block buy_widget_buy_container_pdf_datasheet %}
        <div class="d-grid gap-2 mt-3">
            <a href="{{ path('frontend.pdf_datasheet.get', { 'productNumber': product.productNumber, 'slug': (product.translated.name|pdf_slugify) }) }}"
               class="btn btn-outline-secondary"
               target="_blank"
               title="{{ 'TopdataPdfDatasheetSW6.buttonLabel'|trans }}">
                {# Inline PDF icon representation #}
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-file-earmark-pdf me-2" viewBox="0 0 16 16">
                  <path d="M14 14V4.5L9.5 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2zM9.5 3A1.5 1.5 0 0 0 11 4.5h2V14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h5.5v2z"/>
                  <path d="M4.603 12.087a.81.81 0 0 1-.438-.42c-.046-.083-.053-.175-.053-.264 0-.102.044-.206.12-.284c.08-.082.208-.137.364-.137c.18 0 .378.077.564.214c.244.18.472.463.646.777l.08.148c-.282.022-.547.043-.787.066a7.99 7.99 0 0 1-.496.002zm1.838-1.64c.01-.01.018-.022.024-.031a.143.143 0 0 1 .017-.023c.112-.163.264-.42.363-.736c.095-.303.145-.617.145-.874c0-.284-.1-.581-.314-.73c-.114-.08-.256-.113-.384-.09c-.118.02-.224.085-.3.184c-.087.112-.133.277-.133.475c0 .38.15.8.38 1.194l.054.094c-.076.2-.164.405-.26.612c-.297.614-.643 1.178-.996 1.484l-.073.058c-.147.105-.352.184-.54.184c-.017 0-.033 0-.05-.002c-.07.012-.15.022-.24.03l-.04.004a.735.735 0 0 1-.334-.04c-.115-.045-.205-.133-.255-.246a.51.51 0 0 1-.035-.164c0-.134.036-.264.103-.38c.08-.142.204-.265.367-.356c.18-.1.408-.163.666-.188l.142-.012c.213-.42.435-.858.653-1.28c.117-.225.23-.45.337-.672c-.115-.408-.18-.832-.18-1.258c0-.393.07-.733.213-.984c.142-.25.358-.4.62-.436a.65.65 0 0 1 .376.046c.214.09.356.28.406.52c.046.223.01.492-.102.766a4.246 4.246 0 0 1-.48 1.054a10.022 10.022 0 0 1-.603 1.026c-.024.032-.05.066-.076.1c.365.2.73.435 1.08.703c.364.276.69.59 1.02.94a.513.513 0 0 1 .018-.002c.12-.018.252-.016.368.01a.543.543 0 0 1 .306.18c.085.105.117.226.117.343c0 .12-.036.242-.1.343a.591.591 0 0 1-.264.225c-.14.055-.306.06-.474.015c-.13-.035-.26-.1-.384-.185c-.24-.165-.48-.415-.71-.7l-.11-.143a5.21 5.21 0 0 1-.168-.22zm-1.803-1.428c.28-.24.568-.526.837-.84l-.066-.118a3.25 3.25 0 0 0-.416-.62a3.896 3.896 0 0 0-.306-.343a4.137 4.137 0 0 0-.083 1.043c.01.294.01.594.034.878zM11.578 13.7l.026-.007a.262.262 0 0 0 .1-.065a.157.157 0 0 0 .044-.092c.002-.03-.004-.063-.02-.09a.127.127 0 0 0-.065-.054a.17.17 0 0 0-.083-.007c-.113.012-.24.026-.37.042c.15.113.276.2.363.266z"/>
                </svg>
                {{ 'TopdataPdfDatasheetSW6.buttonLabel'|trans }}
            </a>
        </div>
    {% endblock %}
{% endblock %}
```

---

## 6. Verification & Test Plan

### Automatic Visual Execution (Debug Mode)
Navigate to the storefront address of an active product page, and append `?debug=1` to the path:
* `/datasheet/{productNumber}/{slug}.pdf?debug=1`

Verify that:
1. The HTML payload compiles and renders cleanly in standard browser web-inspectors.
2. The custom `focus_shop` template pulls Poppins and Roboto fonts correctly.
3. Rounded product cards, badges, and colors matches brand values exactly.
4. Images are cleanly converted to native base64 inline strings inside the source files.

### Gotenberg API Communication Checks
Call the product datasheet URL path without parameters:
* `/datasheet/{productNumber}/{slug}.pdf`

Verify that:
1. Gotenberg accepts the payload, compiles the modern flex elements perfectly, and returns an `application/pdf` inline stream successfully.
2. Caching tags respond dynamically based on changes inside system configuration panels.

---

## 7. Report Generation Plan
The final task after completing implementation will be to write a comprehensive report detailing the files created, configuration settings, styling guidelines, and test results. This report will be saved to:
`_ai/backlog/reports/260301_0930__IMPLEMENTATION_REPORT__gotenberg_pdf_datasheet.md`

