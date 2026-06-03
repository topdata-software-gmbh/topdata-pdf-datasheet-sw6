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
