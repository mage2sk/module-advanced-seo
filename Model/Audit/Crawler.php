<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Crawler
{
    private const TIMEOUT_SECONDS = 10;
    private const USER_AGENT      = 'PanthSEO-CrawlAudit/1.0';

    private const ENV_INTERNAL_HOST = 'PANTH_SEO_CRAWL_INTERNAL_HOST';

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function crawl(int $storeId, int $maxPages = 100): array
    {
        $store   = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');
        $host    = (string) parse_url($baseUrl, PHP_URL_HOST);

        if ($host === '') {
            $this->logger->warning('Panth SEO Crawler: could not determine host for store ' . $storeId);
            return [];
        }

        $curl = $this->createCurl();

        $visited = [];
        $queue   = [$this->normalizeUrl($baseUrl, $baseUrl)];
        $head    = 0;

        $firstRequest = true;

        while ($head < count($queue) && count($visited) < $maxPages) {
            $url = $queue[$head++];

            if (isset($visited[$url])) {
                continue;
            }

            [$result, $body] = $this->fetchPage($curl, $url);

            if ($firstRequest && $result->statusCode === 0) {
                $this->logger->warning(sprintf(
                    'Panth SEO Crawler: base URL %s is unreachable from this environment; aborting crawl. '
                    . 'If running inside a container, set the %s env var to an internal host (e.g. "http://hyva_nginx").',
                    $url,
                    self::ENV_INTERNAL_HOST
                ));
                return [];
            }
            $firstRequest = false;

            $visited[$url] = $result;

            if ($this->hasRobotsDirective($result->robots, 'nofollow')) {
                continue;
            }

            if ($body === '' || $result->statusCode === 0) {
                continue;
            }

            $links = $this->extractLinks($body, $url, $host, $baseUrl);
            foreach ($links as $link) {
                if (!isset($visited[$link]) && !in_array($link, $queue, true)) {
                    $queue[] = $link;
                }
            }
        }

        return array_values($visited);
    }

    private function createCurl(): Curl
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, true);
        $curl->setOption(CURLOPT_MAXREDIRS, 5);
        $curl->setOption(CURLOPT_ENCODING, '');
        $curl->setOption(CURLOPT_USERAGENT, self::USER_AGENT);

        $curl->setOption(CURLOPT_SSL_VERIFYPEER, false);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, false);

        return $curl;
    }

    private function applyInternalHost(string $url, ?string &$originalHost): string
    {
        $originalHost = null;

        $override = getenv(self::ENV_INTERNAL_HOST);
        if (!is_string($override) || $override === '') {
            return $url;
        }

        $parsedOverride = parse_url($override);
        if (!$parsedOverride || empty($parsedOverride['host'])) {
            return $url;
        }

        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            return $url;
        }

        $originalHost = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');

        $scheme = $parsedOverride['scheme'] ?? 'http';
        $host   = $parsedOverride['host'];
        $port   = isset($parsedOverride['port']) ? ':' . $parsedOverride['port'] : '';
        $path   = $parsed['path'] ?? '/';
        $query  = isset($parsed['query']) ? '?' . $parsed['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }

    private function fetchPage(Curl $curl, string $url): array
    {
        $transportUrl = $this->applyInternalHost($url, $originalHost);
        if ($originalHost !== null) {
            $curl->addHeader('Host', $originalHost);
        }

        try {
            $curl->get($transportUrl);
            $statusCode = $curl->getStatus();
            $body       = $curl->getBody();
        } catch (\Throwable $e) {
            $this->logger->debug('Panth SEO Crawler: failed to fetch ' . $url . ' - ' . $e->getMessage());
            return [
                new CrawlResult(
                    url: $url,
                    statusCode: 0,
                    issues: ['Fetch failed: ' . $e->getMessage()]
                ),
                '',
            ];
        }

        $title       = $this->extractTag($body, '<title>', '</title>');
        $description = $this->extractMetaContent($body, 'description');
        $canonical   = $this->extractCanonical($body);
        $robots      = $this->extractMetaContent($body, 'robots');

        return [
            new CrawlResult(
                url: $url,
                statusCode: $statusCode,
                title: $title,
                description: $description,
                canonical: $canonical,
                robots: $robots
            ),
            $body,
        ];
    }

    private function extractLinks(string $body, string $pageUrl, string $host, string $baseUrl): array
    {
        $links = [];
        if (preg_match_all('/<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $body, $matches)) {
            foreach ($matches[1] as $index => $href) {
                $tag = $matches[0][$index];
                if (preg_match('/\brel\s*=\s*["\'][^"\']*nofollow[^"\']*["\']/i', $tag)) {
                    continue;
                }

                $resolved = $this->resolveUrl($href, $pageUrl);
                if ($resolved === null) {
                    continue;
                }

                $linkHost = (string) parse_url($resolved, PHP_URL_HOST);
                if (strcasecmp($linkHost, $host) !== 0) {
                    continue;
                }

                $normalized = $this->normalizeUrl($resolved, $baseUrl);

                $path = (string) parse_url($normalized, PHP_URL_PATH);
                if (preg_match('/\.(jpg|jpeg|png|gif|svg|webp|css|js|pdf|zip|ico|woff2?|ttf|eot)$/i', $path)) {
                    continue;
                }

                if (str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
                    continue;
                }

                $links[] = $normalized;
            }
        }

        return array_unique($links);
    }

    private function resolveUrl(string $href, string $pageUrl): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            $scheme = parse_url($pageUrl, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $href;
        }

        $parsed = parse_url($pageUrl);
        $scheme = ($parsed['scheme'] ?? 'https') . '://';
        $host   = $parsed['host'] ?? '';
        $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        if (str_starts_with($href, '/')) {
            return $scheme . $host . $port . $href;
        }

        $basePath = $parsed['path'] ?? '/';
        $basePath = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
        return $scheme . $host . $port . $basePath . $href;
    }

    private function normalizeUrl(string $url, string $baseUrl): string
    {
        $pos = strpos($url, '#');
        if ($pos !== false) {
            $url = substr($url, 0, $pos);
        }

        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return $url;
        }

        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host   = strtolower($parsed['host']);
        $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path   = $parsed['path'] ?? '/';
        $query  = $parsed['query'] ?? '';

        if ($query !== '') {
            parse_str($query, $params);
            ksort($params);
            $query = '?' . http_build_query($params);
        }

        return $scheme . '://' . $host . $port . $path . $query;
    }

    private function extractTag(string $html, string $open, string $close): string
    {
        $start = stripos($html, $open);
        if ($start === false) {
            return '';
        }
        $start += strlen($open);
        $end = stripos($html, $close, $start);
        if ($end === false) {
            return '';
        }

        return trim(html_entity_decode(strip_tags(substr($html, $start, $end - $start)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function extractMetaContent(string $html, string $name): string
    {
        $pattern = '/<meta\b[^>]*\bname\s*=\s*["\']' . preg_quote($name, '/') . '["\']\s*[^>]*\bcontent\s*=\s*["\']([^"\']*)["\'][^>]*>/i';
        if (preg_match($pattern, $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $pattern2 = '/<meta\b[^>]*\bcontent\s*=\s*["\']([^"\']*)["\'][^>]*\bname\s*=\s*["\']' . preg_quote($name, '/') . '["\']/i';
        if (preg_match($pattern2, $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private function extractCanonical(string $html): string
    {
        if (preg_match('/<link\b[^>]*\brel\s*=\s*["\']canonical["\'][^>]*\bhref\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/<link\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\'][^>]*\brel\s*=\s*["\']canonical["\']/i', $html, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function hasRobotsDirective(string $robots, string $directive): bool
    {
        if ($robots === '') {
            return false;
        }
        $directives = array_map('trim', explode(',', strtolower($robots)));
        return in_array(strtolower($directive), $directives, true);
    }
}
