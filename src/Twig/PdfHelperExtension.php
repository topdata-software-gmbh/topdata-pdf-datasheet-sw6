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
            $cleanPath = explode('?', $path, 2)[0];
            $cleanPath = explode('#', $cleanPath, 2)[0];

            $candidates = [];

            if (preg_match('#/(media/.+)#', $cleanPath, $matches)) {
                $candidates[] = $matches[1];
                $candidates[] = preg_replace('#^media/#', '', $matches[1]);
            }

            if (preg_match('#^media/.+#', $cleanPath)) {
                $candidates[] = $cleanPath;
                $candidates[] = preg_replace('#^media/#', '', $cleanPath);
            }

            $candidates = array_unique(array_filter($candidates));

            foreach ($candidates as $candidate) {
                if ($this->publicFilesystem->has($candidate)) {
                    $data = $this->publicFilesystem->read($candidate);
                    $mimeType = $this->publicFilesystem->mimeType($candidate) ?: 'image/png';
                    return 'data:' . $mimeType . ';base64,' . base64_encode($data);
                }
            }

            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'ignore_errors' => true
                ]
            ]);

            $data = @file_get_contents($cleanPath, false, $context);
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
