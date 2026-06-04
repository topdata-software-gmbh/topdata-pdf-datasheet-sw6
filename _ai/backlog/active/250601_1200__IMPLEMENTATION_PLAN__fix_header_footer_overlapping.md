---
filename: "_ai/backlog/active/250601_1200__IMPLEMENTATION_PLAN__fix_header_footer_overlapping.md"
title: "Fix Header and Footer Overlapping in Generated PDF Datasheets"
createdAt: 2025-06-01 12:00
updatedAt: 2025-06-01 12:00
status: in-progress
priority: critical
tags: [pdf, gotenberg, layout, sw6.7]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

# Problem Description
In the current implementation of the PDF datasheet generator, the header and footer templates overlap with the main product content. 

This issue is caused by two overlapping factors:
1. The CSS in the main templates (`focus_shop.html.twig`) specifies `@page { margin: 0; }`. In headless Chromium (utilized by Gotenberg), setting `@page { margin: 0; }` overrides the page margin parameters defined in the API request, causing the main body content to render at the absolute top and bottom edges of the page, directly underneath the header and footer containers.
2. The default margin configurations (`0.75` inches) do not provide sufficient space for the heights of the customized header and footer templates, while the templates themselves contain internal horizontal and vertical padding styles that conflict with the page-level margins.

---

# Executive Summary
To resolve the overlapping layout issues, the following adjustments will be made:
- **Remove Hardcoded Zero Margins**: Eliminate `@page { margin: 0; }` from the print CSS in the product templates, allowing Chromium to respect the layout margins defined dynamically in the system config.
- **Optimize Default Margins**: Increase default top and bottom margin settings in the configuration (`config.xml`) and the controller fallback logic to provide safe, dedicated spatial envelopes for the header (`1.2` in) and footer (`1.0` in).
- **Harmonize Layout Padding**: Remove horizontal paddings from the header, footer, and body templates so they naturally align with the page margin boundaries. Add precise top/bottom paddings in the header and footer files to center the elements gracefully within the margin space.

---

# Project Environment Details
- **Project Name**: SW6.7 Plugin (Topdata PDF Datasheet SW6)
- **Backend root**: `src`
- **PHP Version**: 8.2 / 8.3 / 8.4

---

# Implementation Steps

## Phase 1: Update Plugin Margin Settings and Defaults
Modify the default system configuration values to offer enough spatial height for the header and footer templates.

### `[MODIFY]` `src/Resources/config/config.xml`
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
        <title>Disk-Based Cache Settings</title>
        <title lang="de-DE">Festplatten-Cache Einstellungen</title>

        <input-field type="bool">
            <name>diskCacheEnabled</name>
            <label>Enable Disk Caching</label>
            <label lang="de-DE">Festplatten-Caching aktivieren</label>
            <defaultValue>false</defaultValue>
        </input-field>

        <input-field type="int">
            <name>diskCacheTtl</name>
            <label>Disk Cache TTL (seconds)</label>
            <label lang="de-DE">Festplatten-Cache TTL (Sekunden)</label>
            <defaultValue>86400</defaultValue>
        </input-field>
    </card>

    <card>
        <title>PDF Document Setup</title>
        <title lang="de-DE">PDF-Dokumenteneinstellungen</title>

        <input-field type="float">
            <name>marginTop</name>
            <label>Top Margin (inches)</label>
            <label lang="de-DE">Oberer Rand (Zoll)</label>
            <defaultValue>1.2</defaultValue>
        </input-field>

        <input-field type="float">
            <name>marginBottom</name>
            <label>Bottom Margin (inches)</label>
            <label lang="de-DE">Unterer Rand (Zoll)</label>
            <defaultValue>1.0</defaultValue>
        </input-field>

        <input-field type="float">
            <name>marginLeft</name>
            <label>Left Margin (inches)</label>
            <label lang="de-DE">Linker Rand (Zoll)</label>
            <defaultValue>0.75</defaultValue>
        </input-field>

        <input-field type="float">
            <name>marginRight</name>
            <label>Right Margin (inches)</label>
            <label lang="de-DE">Rechter Rand (Zoll)</label>
            <defaultValue>0.75</defaultValue>
        </input-field>
    </card>
</config>
```

### `[MODIFY]` `src/Controller/PdfDatasheetController.php`
Update the Controller fallback default configurations in case the plugin system configuration parameters are absent or return empty.
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
        private readonly SystemConfigService $systemConfigService,
        private readonly string $cacheDir
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
        $theme = $this->systemConfigService->getString('TopdataPdfDatasheetSW6.config.pdfTheme', $salesChannelId) ?: 'focus_shop';

        $diskCacheEnabled = $this->systemConfigService->getBool('TopdataPdfDatasheetSW6.config.diskCacheEnabled', $salesChannelId);
        $diskCacheTtl = $this->systemConfigService->getInt('TopdataPdfDatasheetSW6.config.diskCacheTtl', $salesChannelId) ?: 86400;
        $cacheEnabled = $this->systemConfigService->getBool('TopdataPdfDatasheetSW6.config.cacheEnabled', $salesChannelId);

        $cacheFile = null;
        if ($diskCacheEnabled && !$request->query->has('debug')) {
            $cacheSubdir = $this->cacheDir . '/topdata_pdf_datasheet';
            $cacheKey = md5($productNumber . '_' . $theme . '_' . $context->getLanguageId() . '_' . $salesChannelId);
            $cacheFile = $cacheSubdir . '/' . $cacheKey . '.pdf';

            if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $diskCacheTtl)) {
                $pdfContent = file_get_contents($cacheFile);
                return $this->createPdfResponse($pdfContent, $productNumber, $cacheEnabled, $theme);
            }
        }

        $product = $this->loadProductByNumber($productNumber, $context);
        if (!$product) {
            throw new ProductNotFoundException($productNumber);
        }

        $gotenbergUrl = $this->systemConfigService->getString('TopdataPdfDatasheetSW6.config.gotenbergUrl', $salesChannelId);

        if (empty($gotenbergUrl)) {
            return new Response('Gotenberg Service URL is not configured.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $margins = [
            'marginTop' => $this->systemConfigService->getFloat('TopdataPdfDatasheetSW6.config.marginTop', $salesChannelId) ?: 1.2,
            'marginBottom' => $this->systemConfigService->getFloat('TopdataPdfDatasheetSW6.config.marginBottom', $salesChannelId) ?: 1.0,
            'marginLeft' => $this->systemConfigService->getFloat('TopdataPdfDatasheetSW6.config.marginLeft', $salesChannelId) ?: 0.75,
            'marginRight' => $this->systemConfigService->getFloat('TopdataPdfDatasheetSW6.config.marginRight', $salesChannelId) ?: 0.75,
        ];

        $templatePath = sprintf('@TopdataPdfDatasheetSW6/storefront/datasheet/%s.html.twig', $theme);
        $headerTemplatePath = sprintf('@TopdataPdfDatasheetSW6/storefront/datasheet/%s_header.html.twig', $theme);
        $footerTemplatePath = sprintf('@TopdataPdfDatasheetSW6/storefront/datasheet/%s_footer.html.twig', $theme);

        $htmlContent = $this->renderView($templatePath, [
            'product' => $product,
            'context' => $context
        ]);

        $headerHtml = '';
        try {
            $headerHtml = $this->renderView($headerTemplatePath, [
                'product' => $product,
                'context' => $context
            ]);
        } catch (\Throwable) {
            // Header template does not exist or failed to render
        }

        $footerHtml = '';
        try {
            $footerHtml = $this->renderView($footerTemplatePath, [
                'product' => $product,
                'context' => $context
            ]);
        } catch (\Throwable) {
            // Footer template does not exist or failed to render
        }

        if ($request->query->has('debug')) {
            $debugFile = $this->cacheDir . '/topdata_pdf_datasheet_debug.html';
            @file_put_contents($debugFile, $htmlContent);
            return new Response($htmlContent, Response::HTTP_OK, ['Content-Type' => 'text/html']);
        }

        try {
            $pdfContent = $this->gotenbergClient->convertHtml($gotenbergUrl, $htmlContent, $margins, $headerHtml, $footerHtml);
        } catch (\Throwable $e) {
            return new Response($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($diskCacheEnabled && $cacheFile) {
            if (!is_dir(dirname($cacheFile))) {
                @mkdir(dirname($cacheFile), 0755, true);
            }
            @file_put_contents($cacheFile, $pdfContent);
        }

        return $this->createPdfResponse($pdfContent, $productNumber, $cacheEnabled, $theme);
    }

    private function createPdfResponse(string $pdfContent, string $productNumber, bool $cacheEnabled, string $theme): Response
    {
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
        $criteria->addAssociation('media.media');
        $criteria->addAssociation('cover');
        $criteria->addAssociation('cover.media');
        $criteria->addAssociation('properties.group');
        $criteria->addAssociation('manufacturer');
        $criteria->setLimit(1);

        return $this->productRepository->search($criteria, $context)->first();
    }
}
```

---

## Phase 2: Restructure Main Layout Twig Templates
Adjust the main product templates to inherit A4 styling and remove any hardcoded internal borders or body-level paddings.

### `[MODIFY]` `src/Resources/views/storefront/datasheet/focus_shop.html.twig`
```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ product.translated.name }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        @page {
            size: A4;
        }
        body {
            font-family: 'Roboto', sans-serif;
            font-size: 14px;
            color: #494e50;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            background-color: #ffffff;
        }
        h1, h2, h3, h4 {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            color: #3c3e40;
            margin-top: 0;
        }
        .container {
            max-width: 100%;
            margin: 0 auto;
        }
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
            border: 2px solid #c7c8c8;
            border-radius: 8px;
            padding: 15px;
            display: inline-block;
            max-width: 100%;
            margin-bottom: 10px;
        }
        .product-image {
            max-width: 100%;
            max-height: 280px;
            object-fit: contain;
        }
        .thumbnail-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: center;
            margin-top: 15px;
        }
        .thumbnail-card {
            background-color: #ffffff;
            border: 1px solid #c7c8c8;
            border-radius: 4px;
            padding: 5px;
            display: inline-block;
            width: 60px;
            height: 60px;
        }
        .thumbnail-image {
            max-width: 100%;
            max-height: 100%;
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
            break-inside: avoid;
            page-break-inside: avoid;
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
    </style>
</head>
<body>

<div class="container">
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

            {% if product.media and product.media.count > 1 %}
                <div class="thumbnail-container">
                    {% for productMedia in product.media %}
                        {% if not product.cover or productMedia.mediaId != product.cover.mediaId %}
                            <div class="thumbnail-card">
                                <img class="thumbnail-image" src="{{ productMedia.media.url|pdf_base64_image }}" alt="Product Thumbnail">
                            </div>
                        {% endif %}
                    {% endfor %}
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
</div>

</body>
</html>
```

### `[MODIFY]` `src/Resources/views/storefront/datasheet/minimal.html.twig`
```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ product.translated.name }} - Datasheet</title>
    <style>
        @page {
            size: A4;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 13px;
            color: #333333;
            line-height: 1.5;
            margin: 0;
            padding: 0;
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
            margin-bottom: 10px;
        }
        .thumbnail-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 10px;
        }
        .thumbnail-image {
            max-width: 50px;
            max-height: 50px;
            object-fit: contain;
            border: 1px solid #eee;
            padding: 3px;
        }
        .section-title {
            font-size: 16px;
            font-weight: bold;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
            margin: 25px 0 15px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .specs-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .specs-table tr {
            break-inside: avoid;
            page-break-inside: avoid;
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

            {% if product.media and product.media.count > 1 %}
                <div class="thumbnail-container">
                    {% for productMedia in product.media %}
                        {% if not product.cover or productMedia.mediaId != product.cover.mediaId %}
                            <img class="thumbnail-image" src="{{ productMedia.media.url|pdf_base64_image }}" alt="Product Thumbnail">
                        {% endif %}
                    {% endfor %}
                </div>
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

## Phase 3: Update Header & Footer Styles
Refactor the header and footer CSS styling to align with the new page-level margin properties. Add vertical padding directly inside the body elements to center the printed output vertically.

### `[MODIFY]` `src/Resources/views/storefront/datasheet/focus_shop_header.html.twig`
```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body {
            margin: 0;
            padding: 25px 0 0 0;
            font-family: 'Roboto', 'Helvetica Neue', Arial, sans-serif;
            font-size: 11px;
            color: #828384;
            width: 100%;
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
        }
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-bottom: 2px solid #e96354;
            padding-bottom: 8px;
            width: 100%;
        }
        .logo {
            font-size: 16px;
            font-weight: 700;
            color: #e96354;
        }
        .logo span {
            color: #494e50;
        }
        .claim {
            font-style: italic;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <div class="header-container">
        <div class="logo">focus<span>shop</span></div>
        <div class="claim">{{ "TopdataPdfDatasheetSW6.brandClaim"|trans }}</div>
    </div>
</body>
</html>
```

### `[MODIFY]` `src/Resources/views/storefront/datasheet/focus_shop_footer.html.twig`
```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body {
            margin: 0;
            padding: 0 0 25px 0;
            font-family: 'Roboto', 'Helvetica Neue', Arial, sans-serif;
            font-size: 9px;
            color: #828384;
            width: 100%;
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
        }
        .footer-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #c7c8c8;
            padding-top: 8px;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="footer-container">
        <div>&copy; {{ "now"|date("Y") }} Focus Shop. All rights reserved.</div>
        <div>{{ "TopdataPdfDatasheetSW6.brandClaim"|trans }} | Page <span class="pageNumber"></span> of <span class="totalPages"></span></div>
    </div>
</body>
</html>
```

### `[MODIFY]` `src/Resources/views/storefront/datasheet/minimal_header.html.twig`
```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body {
            margin: 0;
            padding: 20px 0 0 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #333333;
            width: 100%;
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
        }
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-bottom: 2px solid #333333;
            padding-bottom: 6px;
            width: 100%;
        }
        .title {
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header-container">
        <div class="title">{{ product.translated.name }}</div>
        <div>{{ "TopdataPdfDatasheetSW6.productNumber"|trans }}: {{ product.productNumber }}</div>
    </div>
</body>
</html>
```

### `[MODIFY]` `src/Resources/views/storefront/datasheet/minimal_footer.html.twig`
```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body {
            margin: 0;
            padding: 0 0 20px 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #666666;
            width: 100%;
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
        }
        .footer-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #dddddd;
            padding-top: 6px;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="footer-container">
        <div>
            {% if product.manufacturer %}
                {{ product.manufacturer.translated.name }}
            {% endif %}
        </div>
        <div>Page <span class="pageNumber"></span> / <span class="totalPages"></span></div>
    </div>
</body>
</html>
```

---

## Phase 4: Create Implementation Report
The AI agent will write the implementation details to the reporting folder at the end of the run.

### `[NEW FILE]` `_ai/backlog/reports/250601_1200__IMPLEMENTATION_REPORT__fix_header_footer_overlapping.md`
```yaml
---
filename: "_ai/backlog/reports/250601_1200__IMPLEMENTATION_REPORT__fix_header_footer_overlapping.md"
title: "Report: Fix Header and Footer Overlapping in Generated PDF Datasheets"
createdAt: 2025-06-01 12:15
updatedAt: 2025-06-01 12:15
planFile: "_ai/backlog/active/250601_1200__IMPLEMENTATION_PLAN__fix_header_footer_overlapping.md"
project: "Topdata PDF Datasheet SW6"
status: completed
filesCreated: 0
filesModified: 8
filesDeleted: 0
tags: [pdf, gotenberg, layout, sw6.7]
documentType: IMPLEMENTATION_REPORT
---

## Summary
The overlapping layout issue between page headers/footers and the main body content has been resolved. By removing the hardcoded `@page { margin: 0; }` css declarations and adjusting the page margin system settings, Chromium is now able to utilize proper margin dimensions to keep headers, footers, and body text within separate boundaries.

## Files Changed
- `src/Resources/config/config.xml` (Optimized default top/bottom margins)
- `src/Controller/PdfDatasheetController.php` (Updated fallback default margin settings)
- `src/Resources/views/storefront/datasheet/focus_shop.html.twig` (Removed hardcoded margins, updated padding)
- `src/Resources/views/storefront/datasheet/minimal.html.twig` (Removed body padding, structured page config)
- `src/Resources/views/storefront/datasheet/focus_shop_header.html.twig` (Removed horizontal padding, aligned and shifted content via vertical padding)
- `src/Resources/views/storefront/datasheet/focus_shop_footer.html.twig` (Removed horizontal padding, aligned and shifted content via vertical padding)
- `src/Resources/views/storefront/datasheet/minimal_header.html.twig` (Cleaned layout padding)
- `src/Resources/views/storefront/datasheet/minimal_footer.html.twig` (Cleaned layout padding)

## Key Changes
- Removed CSS `@page { margin: 0; }` configuration inside the product templates which overrode the PDF rendering margins.
- Expanded default page margins in config files from `0.75` inches to `1.2` (Top) and `1.0` (Bottom) to ensure header/footer templates do not overflow the main layout space.
- Adjusted horizontal paddings in the header, footer, and body files to zero, enforcing exact lateral alignment guided natively by the document's left and right margins (`0.75` inches).
- Styled the template bodies with minor vertical spacing offsets to position headers and footers neatly inside their margin zones without touching the physical borders of the paper sheet.

## Technical Decisions
Utilizing CSS settings to define size dimensions while managing print boundaries directly with Gotenberg's API parameters provides the most robust layout separation. This division prevents manual styling variables inside separate Twig files from causing engine-level margin collapse.
```
