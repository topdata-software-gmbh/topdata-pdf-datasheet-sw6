---
filename: "_ai/backlog/active/260601_1000__IMPLEMENTATION_PLAN__pdf_datasheet_fixes_and_cache.md"
title: "Fix PDF broken images, layout page breaks, add disk caching and multi-image support"
createdAt: 2026-06-01 10:00
updatedAt: 2026-06-01 10:00
status: draft
priority: high
tags: [shopware, gotenberg, pdf-datasheet, caching, bugfix]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

# Implementation Plan - PDF Datasheet Fixes and Caching Support

## 1. Problem Description
The `TopdataPdfDatasheetSW6` plugin currently experiences several limitations and styling bugs:
1. **Broken Images:** The product images in the generated PDFs render as broken placeholder links. This is caused by the Twig filter `pdf_base64_image` attempting to use a Laravel helper `public_path()` which is undefined in Symfony-based Shopware. It also attempts loopback HTTP requests to fetch media URLs, which often fail or time out inside isolated Docker or local development environments.
2. **Page-Break Layout Issues:** Technical specifications are cut in the middle. The spec labels render at the bottom of the first page, and their corresponding values break onto the next page due to a lack of page-break CSS controls.
3. **No Disk Caching:** PDF rendering is generated on-the-fly via Gotenberg for every request, which incurs high network and CPU overhead. A disk-based cache with a configurable TTL is required to optimize load times.
4. **No CLI Cache Tool:** There is no mechanism to clear the generated PDF cache except by manually clearing the entire shop cache or accessing the filesystem.
5. **No Multi-Image Support:** Only the main cover image is shown on the datasheet, even when other images are available for the product.

## 2. Executive Summary
This plan addresses these issues through:
- Integrating Shopware's Flysystem (`shopware.filesystem.public`) in the Twig extension to directly load media files from local disk or storage, with an HTTP fallback using raw requests.
- Applying CSS properties (`break-inside: avoid` and `page-break-inside: avoid`) to prevent layout elements and table rows from splitting across PDF page breaks.
- Creating an optional disk-based cache inside Symfony's cache directory with adjustable TTL settings inside the plugin configuration.
- Developing a custom CLI command `topdata:pdf-datasheet:cache-clear` that inherits the foundation's `CliLogger` output rules.
- Updating both the `minimal` and `focus_shop` themes to loop through and render additional product thumbnails.

## 3. Project Environment Details
- Project Name: SW6.7 Plugin
- Backend root: `src`
- PHP Version: `8.2 / 8.3 / 8.4`

---

## 4. Implementation Steps

### Phase 1: Config and Service Layer Update
We will introduce configuration settings for disk-based caching and configure dependencies in the service container.

#### [MODIFY] `src/Resources/config/config.xml`
Adding `diskCacheEnabled` and `diskCacheTtl` settings to the plugin config.

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

#### [MODIFY] `src/Resources/config/services.xml`
Registering the new custom console command and injecting `%kernel.cache_dir%` and `shopware.filesystem.public`.

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
            <argument type="service" id="shopware.filesystem.public"/>
            <tag name="twig.extension"/>
        </service>

        <!-- Main PDF Generator Controller -->
        <service id="Topdata\TopdataPdfDatasheetSW6\Controller\PdfDatasheetController" public="true">
            <argument type="service" key="$productRepository" id="sales_channel.product.repository"/>
            <argument type="string" key="$cacheDir">%kernel.cache_dir%</argument>
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>

        <!-- CLI Commands -->
        <service id="Topdata\TopdataPdfDatasheetSW6\Command\CacheClearCommand">
            <argument type="string" key="$cacheDir">%kernel.cache_dir%</argument>
            <tag name="console.command"/>
        </service>
    </services>
</container>
```

---

### Phase 2: Twig Extensions and Image Fixes
Let's resolve the broken image generation. We replace `public_path()` with direct public Flysystem checks.

#### [MODIFY] `src/Twig/PdfHelperExtension.php`
Updating filesystem injection and URL path resolution patterns.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataPdfDatasheetSW6\Twig;

use League\Flysystem\FilesystemOperator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class PdfHelperExtension extends AbstractExtension
{
    public function __construct(
        private readonly FilesystemOperator $publicFilesystem
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('pdf_base64_image', [$this, 'base64Image']),
            new TwigFilter('pdf_slugify', [$this, 'slugify']),
        ];
    }

    public function base64Image(?string $path): string
    {
        if (empty($path)) {
            return '';
        }

        try {
            // Extract the relative media path from the URL
            $relativePath = null;
            if (preg_match('#media/(.+)#', $path, $matches)) {
                $relativePath = 'media/' . $matches[1];
            }

            // Attempt to retrieve directly from Flysystem (local public disk, S3, etc.)
            if ($relativePath && $this->publicFilesystem->has($relativePath)) {
                $data = $this->publicFilesystem->read($relativePath);
                $mimeType = $this->publicFilesystem->mimeType($relativePath) ?: 'image/png';
                return 'data:' . $mimeType . ';base64,' . base64_encode($data);
            }

            // Fallback: Attempt HTTP request if Flysystem cannot resolve the file path
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
            return '';
        }
    }

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

### Phase 3: Datasheet Template Improvements
We will apply CSS page breaking controls to avoid grid and row breaks. In addition, we loop through additional images and display them beneath the cover photo.

#### [MODIFY] `src/Resources/views/storefront/datasheet/focus_shop.html.twig`
Adding page breaks for `.spec-card` and rendering secondary images.

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
            margin: 0;
        }
        body {
            font-family: 'Roboto', sans-serif;
            font-size: 14px;
            color: #494e50;
            line-height: 1.6;
            margin: 0;
            padding: 40px;
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
        .brand-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-bottom: 3px solid #e96354;
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
            color: #828384;
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
        .brand-footer {
            margin-top: 50px;
            border-top: 1px solid #c7c8c8;
            padding-top: 15px;
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #828384;
            break-inside: avoid;
            page-break-inside: avoid;
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

    <div class="brand-footer">
        <div>&copy; {{ "now"|date("Y") }} Focus Shop. All rights reserved.</div>
        <div>{{ "TopdataPdfDatasheetSW6.brandClaim"|trans }}</div>
    </div>
</div>

</body>
</html>
```

#### [MODIFY] `src/Resources/views/storefront/datasheet/minimal.html.twig`
Fixing table rows page splitting issues and rendering additional images in the sidebar.

```html
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

### Phase 4: Disk Caching Implementation
We modify the Controller class to check for cached PDFs and return them immediately, bypassing Gotenberg rendering and product lookup queries.

#### [MODIFY] `src/Controller/PdfDatasheetController.php`
Refactoring the controller to introduce optional disk-based caching.

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
            'marginTop' => $this->systemConfigService->getFloat('TopdataPdfDatasheetSW6.config.marginTop', $salesChannelId) ?: 0.75,
            'marginBottom' => $this->systemConfigService->getFloat('TopdataPdfDatasheetSW6.config.marginBottom', $salesChannelId) ?: 0.75,
            'marginLeft' => $this->systemConfigService->getFloat('TopdataPdfDatasheetSW6.config.marginLeft', $salesChannelId) ?: 0.5,
            'marginRight' => $this->systemConfigService->getFloat('TopdataPdfDatasheetSW6.config.marginRight', $salesChannelId) ?: 0.5,
        ];

        $templatePath = sprintf('@TopdataPdfDatasheetSW6/storefront/datasheet/%s.html.twig', $theme);

        $htmlContent = $this->renderView($templatePath, [
            'product' => $product,
            'context' => $context
        ]);

        if ($request->query->has('debug')) {
            return new Response($htmlContent, Response::HTTP_OK, ['Content-Type' => 'text/html']);
        }

        try {
            $pdfContent = $this->gotenbergClient->convertHtml($gotenbergUrl, $htmlContent, $margins);
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
        $criteria->addAssociation('cover');
        $criteria->addAssociation('properties.group');
        $criteria->addAssociation('manufacturer');
        $criteria->setLimit(1);

        return $this->productRepository->search($criteria, $context)->first();
    }
}
```

---

### Phase 5: CLI Cache Clearing Command
We introduce a CLI console command to flush cached PDFs. It conforms to Topdata coding standards, extending `TopdataFoundationSW6` and employing `CliLogger`.

#### [NEW FILE] `src/Command/CacheClearCommand.php`
Console tool to manage disk cached generated files.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataPdfDatasheetSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Topdata\TopdataFoundationSW6\TopdataFoundationSW6;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

#[AsCommand(
    name: 'topdata:pdf-datasheet:cache-clear',
    description: 'Clears the generated PDF datasheets disk cache'
)]
class CacheClearCommand extends TopdataFoundationSW6
{
    public function __construct(
        private readonly string $cacheDir
    ) {
        parent::__construct();
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $cliStyle = new SymfonyStyle($input, $output);
        CliLogger::setCliStyle($cliStyle);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CliLogger::title('Topdata PDF Datasheet - Cache Clear');

        $cacheSubdir = $this->cacheDir . '/topdata_pdf_datasheet';

        if (!is_dir($cacheSubdir)) {
            CliLogger::info('Cache directory does not exist or is already empty.');
            return self::SUCCESS;
        }

        $files = glob($cacheSubdir . '/*.pdf');
        if (empty($files)) {
            CliLogger::info('No cached PDF files found.');
            return self::SUCCESS;
        }

        $count = 0;
        foreach ($files as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }

        CliLogger::success(sprintf('Cleared %d cached PDF file(s).', $count));

        return self::SUCCESS;
    }
}
```

---

### Phase 6: Write Implementation Report
Write the final execution report once changes are deployed.

#### [NEW FILE] `_ai/backlog/reports/260601_1000__IMPLEMENTATION_REPORT__pdf_datasheet_fixes_and_cache.md`
Report document showing technical changes.

```yaml
---
filename: "_ai/backlog/reports/260601_1000__IMPLEMENTATION_REPORT__pdf_datasheet_fixes_and_cache.md"
title: "Report: Fix PDF broken images, layout page breaks, add disk caching and multi-image support"
createdAt: 2026-06-01 10:00
updatedAt: 2026-06-01 10:00
planFile: "_ai/backlog/active/260601_1000__IMPLEMENTATION_PLAN__pdf_datasheet_fixes_and_cache.md"
project: "SW6.7 Plugin"
status: completed
filesCreated: 2
filesModified: 5
filesDeleted: 0
tags: [shopware, gotenberg, pdf-datasheet, caching, bugfix]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report - PDF Datasheet Fixes and Caching Support

## 1. Summary
The rendering errors on images, page-break cuts, and lack of performance controls have been resolved. The plugin now retrieves image streams via Flysystem filesystem queries to bypass connection drops, limits page breaks inside specs elements, includes custom configurations for enabling optional disk caching, and provides a command tool to purge cached records.

## 2. Files Changed

### New Files:
- `src/Command/CacheClearCommand.php`: Introduces a CLI tool `topdata:pdf-datasheet:cache-clear` to empty the compiled PDF cache.
- `_ai/backlog/reports/260601_1000__IMPLEMENTATION_REPORT__pdf_datasheet_fixes_and_cache.md`: This execution summary report.

### Modified Files:
- `src/Resources/config/config.xml`: Declares configuration keys `diskCacheEnabled` and `diskCacheTtl`.
- `src/Resources/config/services.xml`: Injects `shopware.filesystem.public`, registers cache directory limits, and declares the command line service.
- `src/Twig/PdfHelperExtension.php`: Performs Flysystem reading of product assets before attempting HTTP calls.
- `src/Resources/views/storefront/datasheet/focus_shop.html.twig`: Introduces page break isolation guidelines and loops additional product photos.
- `src/Resources/views/storefront/datasheet/minimal.html.twig`: Ensures proper table layout behavior and shows extra thumbnail attachments.
- `src/Controller/PdfDatasheetController.php`: Integrates checks on local cached binaries to reduce database overhead.

## 3. Key Changes
- **Local File Retrieval:** Resolves media content from Flysystem using `shopware.filesystem.public` to bypass localhost hostname DNS failures in local container systems.
- **Avoid Split Elements:** Included `break-inside: avoid` styles into table columns and CSS Grid spec elements.
- **Disk Caching Pipeline:** Injected kernel directory cache constraints inside the Controller to handle caching before loading SQL rows.
- **Multiple Images:** Appends supplementary product gallery assets as inline thumbnail rows.

## 4. Technical Decisions
- **Flysystem Prioritization:** Using League Flysystem helps handle local and remote S3 storage backends seamlessly without changing Twig template filters.
- **Early Cache Hit Routing:** The cache validation evaluates file names before doing DB product lookup queries. This provides faster responses on cached records.

## 5. Testing Notes
1. Run `bin/console topdata:pdf-datasheet:cache-clear` to confirm command availability and registration.
2. Activate disk cache settings in plugin config and verify generated file structures in `var/cache/[env]/topdata_pdf_datasheet/`.
3. Check PDF structure across page borders to ensure specifications do not break across pages.
```

