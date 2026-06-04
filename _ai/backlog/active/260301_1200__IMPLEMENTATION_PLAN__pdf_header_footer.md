---
filename: "_ai/backlog/active/260301_1200__IMPLEMENTATION_PLAN__pdf_header_footer.md"
title: "Implement Header and Footer on Every Page of Generated PDF Datasheets"
createdAt: 2026-03-01 12:00
updatedAt: 2026-03-01 12:00
status: draft
priority: high
tags: [pdf, gotenberg, header, footer, shopware]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

# Problem Statement
Currently, the generated PDF datasheets only display headers and footers within the main body flow of the document (e.g., in `focus_shop.html.twig`). If a datasheet spans multiple pages, these elements do not repeat on every page. Instead, they appear only at the beginning and the end of the entire document. To ensure a professional layout for multi-page documents, each page of the PDF should have a consistent header and footer.

# Executive Summary
The solution leverages Gotenberg's headless Chromium PDF generation features. Gotenberg allows the rendering of global headers and footers across every page by accepting separate `header.html` and `footer.html` files during the HTML-to-PDF conversion API call.

This plan details:
1. Updating `GotenbergClient` to support uploading `header.html` and `footer.html` as multipart files.
2. Modifying `PdfDatasheetController` to load and render separate header and footer Twig templates for the active theme.
3. Creating new header and footer Twig templates for both the `focus_shop` and `minimal` themes.
4. Adjusting the main body templates (`focus_shop.html.twig` and `minimal.html.twig`) to remove inline headers/footers to avoid duplicates.
5. Verifying margin configurations to ensure there is adequate space for headers and footers to render without overlapping the body content.

# Project Environment
- **Project Name:** SW6.7 Plugin
- **Backend Root:** `src`
- **PHP Version:** 8.2 / 8.3 / 8.4

---

# Multi-Phased Implementation Plan

## Phase 1: Gotenberg Client Integration
We need to update the `GotenbergClient` to accept `$headerHtml` and `$footerHtml` arguments, and compile them as multipart files (`header.html` and `footer.html`) in the request payload.

### [MODIFY] `src/Service/GotenbergClient.php`
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

    public function convertHtml(
        string $gotenbergUrl,
        string $htmlContent,
        array $margins = [],
        string $headerHtml = '',
        string $footerHtml = ''
    ): string {
        $endpoint = rtrim($gotenbergUrl, '/') . '/forms/chromium/convert/html';

        $boundary = '---------------------------' . uniqid('', true);
        $headers = [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ];

        $body = $this->buildMultipartBody($boundary, $htmlContent, $margins, $headerHtml, $footerHtml);

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

    private function buildMultipartBody(
        string $boundary,
        string $htmlContent,
        array $margins,
        string $headerHtml,
        string $footerHtml
    ): string {
        $body = '';

        // Main index.html
        $body .= "--" . $boundary . "\r\n";
        $body .= "Content-Disposition: form-data; name=\"files\"; filename=\"index.html\"\r\n";
        $body .= "Content-Type: text/html\r\n\r\n";
        $body .= $htmlContent . "\r\n";

        // Header HTML if provided
        if (!empty($headerHtml)) {
            $body .= "--" . $boundary . "\r\n";
            $body .= "Content-Disposition: form-data; name=\"files\"; filename=\"header.html\"\r\n";
            $body .= "Content-Type: text/html\r\n\r\n";
            $body .= $headerHtml . "\r\n";
        }

        // Footer HTML if provided
        if (!empty($footerHtml)) {
            $body .= "--" . $boundary . "\r\n";
            $body .= "Content-Disposition: form-data; name=\"files\"; filename=\"footer.html\"\r\n";
            $body .= "Content-Type: text/html\r\n\r\n";
            $body .= $footerHtml . "\r\n";
        }

        // Margins and other configuration parameters
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

## Phase 2: Controller Rendering Flow
We must update `PdfDatasheetController` to render the separate header and footer templates if they exist for the active theme.

### [MODIFY] `src/Controller/PdfDatasheetController.php`
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

## Phase 3: Brand Corporate Design Templates (Focus Shop)
We will now create the header and footer templates for the `focus_shop` design, and remove the hardcoded top/bottom layouts from the main document to avoid overlapping or duplications.

*Note: Chromium's header and footer are rendered in separate isolated sandboxes. System fonts are recommended, and width should be set to 100% with margin/padding adjustments to line up with the main document.*

### [NEW FILE] `src/Resources/views/storefront/datasheet/focus_shop_header.html.twig`
```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body {
            margin: 0;
            padding: 0 40px; /* aligns with body padding */
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

### [NEW FILE] `src/Resources/views/storefront/datasheet/focus_shop_footer.html.twig`
```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body {
            margin: 0;
            padding: 0 40px; /* aligns with body padding */
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
        <div>&copy; {{ "now"|date("Y") }} Focus Shop</div>
        <div>{{ "TopdataPdfDatasheetSW6.brandClaim"|trans }} | Page <span class="pageNumber"></span> of <span class="totalPages"></span></div>
    </div>
</body>
</html>
```

### [MODIFY] `src/Resources/views/storefront/datasheet/focus_shop.html.twig`
Remove the hardcoded brand-header and brand-footer elements from the main layout so they do not overlap with the new global header/footer.
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

---

## Phase 4: Minimal Design Templates
Next, we will implement the header and footer for the `minimal` design, matching its high-density, system-font aesthetic, and modify the main body template accordingly.

### [NEW FILE] `src/Resources/views/storefront/datasheet/minimal_header.html.twig`
```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body {
            margin: 0;
            padding: 0 20px; /* aligns with minimal template body padding */
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

### [NEW FILE] `src/Resources/views/storefront/datasheet/minimal_footer.html.twig`
```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body {
            margin: 0;
            padding: 0 20px; /* aligns with minimal template body padding */
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

### [MODIFY] `src/Resources/views/storefront/datasheet/minimal.html.twig`
Remove the redundant static header details from the main content.
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

## Phase 5: Verification & Documentation Updates
No user documentation changes are strictly required, but testing and cache clearing steps are defined.

### Cache Clearing
Since compiled PDF layouts are cached depending on system configuration, it is recommended to clear the PDF disk cache to verify the visual changes:
```bash
bin/console topdata:pdf-datasheet:cache-clear
```

---

## Phase 6: Write Implementation Report
Once implementation is complete, the AI agent will generate an execution summary report detailing the files modified and created.

### [NEW FILE] `_ai/backlog/reports/260301_1300__IMPLEMENTATION_REPORT__pdf_header_footer.md`
```yaml
---
filename: "_ai/backlog/reports/260301_1300__IMPLEMENTATION_REPORT__pdf_header_footer.md"
title: "Report: Implement Header and Footer on Every Page of Generated PDF Datasheets"
createdAt: 2026-03-01 13:00
updatedAt: 2026-03-01 13:00
planFile: "_ai/backlog/active/260301_1200__IMPLEMENTATION_PLAN__pdf_header_footer.md"
project: "SW6.7 Plugin"
status: completed
filesCreated: 4
filesModified: 4
filesDeleted: 0
tags: [pdf, gotenberg, header, footer]
documentType: IMPLEMENTATION_REPORT
---

# Summary
We successfully updated the PDF generation engine to render headers and footers on every page of the generated PDF document using Gotenberg's Chromium file-injection capabilities. Redundant inline templates inside the main Twig layouts were removed to maintain styling consistency.

# Files Changed
### New Files
- `src/Resources/views/storefront/datasheet/focus_shop_header.html.twig` - Modular header template for the Focus Shop theme.
- `src/Resources/views/storefront/datasheet/focus_shop_footer.html.twig` - Modular footer template for the Focus Shop theme.
- `src/Resources/views/storefront/datasheet/minimal_header.html.twig` - Modular header template for the Minimal theme.
- `src/Resources/views/storefront/datasheet/minimal_footer.html.twig` - Modular footer template for the Minimal theme.

### Modified Files
- `src/Service/GotenbergClient.php` - Added parameters to compile and transmit dynamic `header.html` and `footer.html` multipart payloads.
- `src/Controller/PdfDatasheetController.php` - Configured controller to render distinct header and footer templates per active theme and pass them downstream.
- `src/Resources/views/storefront/datasheet/focus_shop.html.twig` - Refactored main layout to strip inline static header and footer tags.
- `src/Resources/views/storefront/datasheet/minimal.html.twig` - Refactored main layout to strip inline static headers.

# Key Changes
- Integrated global page variables (`pageNumber`, `totalPages`) via native Chromium browser behaviors.
- Set matching CSS margins and padding structures to prevent body copy overlap.
- Enabled multi-page repeating headers/footers for dynamic print environments.

# Technical Decisions
Using separate HTML uploads via the Gotenberg multi-file system is highly recommended over CSS printing workarounds (`position: fixed`). Chromium guarantees that `header.html` and `footer.html` sit in designated margin layers safely separate from document bodies.
```
