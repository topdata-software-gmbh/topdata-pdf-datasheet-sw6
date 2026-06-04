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
        string $headerHtml = '',
        string $footerHtml = ''
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
