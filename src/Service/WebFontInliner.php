<?php declare(strict_types=1);

namespace Topdata\TopdataPdfDatasheetSW6\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WebFontInliner
{
    private const CHROME_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';

    private const SUBSETS = ['latin'];

    private const FETCH_TIMEOUT = 5.0;

    private const FAILURE_MARKER_TTL = 300;

    private const WAIT_POLLS = 30;

    private const WAIT_POLL_INTERVAL_US = 100_000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $cacheDir
    ) {}

    public function inline(string $html): string
    {
        return (string) preg_replace_callback(
            '#<link[^>]+href="(https://fonts\.googleapis\.com/css2[^"]*)"[^>]*>#i',
            function (array $matches): string {
                $css = $this->getFontCss(html_entity_decode($matches[1], ENT_QUOTES));

                if ($css === null) {
                    $this->logger->warning('PDF datasheet: web font inlining failed, stylesheet link stripped', [
                        'url' => $matches[1],
                    ]);

                    return '';
                }

                return '<style>' . $css . '</style>';
            },
            $html
        );
    }

    private function getFontCss(string $url): ?string
    {
        $key = hash('xxh128', $url);
        $cssFile = $this->cacheDir . '/' . $key . '.css';
        $lockFile = $this->cacheDir . '/' . $key . '.lock';
        $failureFile = $this->cacheDir . '/' . $key . '.failed';

        if ($this->isValidBlob($cssFile)) {
            return (string) file_get_contents($cssFile);
        }

        if (is_file($failureFile) && (time() - (int) filemtime($failureFile)) < self::FAILURE_MARKER_TTL) {
            return null;
        }

        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            return null;
        }

        $lockHandle = @fopen($lockFile, 'c');

        if ($lockHandle === false) {
            return null;
        }

        if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
            try {
                $css = $this->fetchAndBuild($url);

                if ($css === null) {
                    @touch($failureFile);

                    return null;
                }

                if (!$this->writeAtomically($cssFile, $css)) {
                    $this->logger->warning('PDF datasheet: could not write web font cache file', ['file' => $cssFile]);
                }

                @unlink($failureFile);
                $this->logger->info('PDF datasheet: fetched and cached web fonts', ['url' => $url, 'key' => $key]);

                return $css;
            } finally {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }

        fclose($lockHandle);

        for ($i = 0; $i < self::WAIT_POLLS; ++$i) {
            usleep(self::WAIT_POLL_INTERVAL_US);
            clearstatcache(true, $cssFile);

            if ($this->isValidBlob($cssFile)) {
                return (string) file_get_contents($cssFile);
            }

            if (is_file($failureFile)) {
                return null;
            }
        }

        return null;
    }

    private function fetchAndBuild(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => self::CHROME_UA],
                'timeout' => self::FETCH_TIMEOUT,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $css = $response->getContent();
        } catch (\Throwable) {
            return null;
        }

        $css = $this->inlineFontFiles($css);

        if ($css === null || $css === '' || !str_contains($css, '@font-face')) {
            return null;
        }

        return $css;
    }

    private function inlineFontFiles(string $css): ?string
    {
        if (!preg_match_all('~/\*\s*([\w-]+)\s*\*/\s*@font-face\s*\{([^}]*)\}~s', $css, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $result = '';

        foreach ($matches as $match) {
            if (!in_array($match[1], self::SUBSETS, true)) {
                continue;
            }

            $block = $match[2];

            if (!preg_match_all('~url\(([\'"]?)(https://fonts\.gstatic\.com/[^)\'"]+)\1\)~', $block, $urlMatches)) {
                continue;
            }

            foreach ($urlMatches[2] as $fontUrl) {
                $dataUri = $this->fetchFontDataUri($fontUrl);

                if ($dataUri === null) {
                    return null;
                }

                $block = (string) preg_replace(
                    '~url\(([\'"]?)' . preg_quote($fontUrl, '~') . '\1\)~',
                    'url(' . $dataUri . ')',
                    $block
                );
            }

            $result .= '@font-face {' . $block . "}\n";
        }

        if ($result === '' || preg_match('~url\(\s*[\'"]?https?://~', $result)) {
            return null;
        }

        return $result;
    }

    private function fetchFontDataUri(string $fontUrl): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $fontUrl, ['timeout' => self::FETCH_TIMEOUT]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $binary = $response->getContent();
        } catch (\Throwable) {
            return null;
        }

        return 'data:font/woff2;base64,' . base64_encode($binary);
    }

    private function isValidBlob(string $file): bool
    {
        if (!is_file($file) || filesize($file) === 0) {
            return false;
        }

        return str_contains((string) file_get_contents($file), '@font-face');
    }

    private function writeAtomically(string $file, string $content): bool
    {
        $tmp = $file . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, $content) === false) {
            return false;
        }

        return @rename($tmp, $file);
    }
}
