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

    public function base64Image(?string $path): string
    {
        if (empty($path)) {
            return '';
        }

        try {
            if (!str_starts_with($path, 'http://') && !str_starts_with($path, 'https://')) {
                $absolutePath = public_path($path);
                if (file_exists($absolutePath)) {
                    $data = file_get_contents($absolutePath);
                    $type = mime_content_type($absolutePath) ?: 'image/png';
                    return 'data:' . $type . ';base64,' . base64_encode($data);
                }
            }

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
