<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\Check\PageCheckInterface;
use Panth\AdvancedSEO\Model\Audit\Check\SiteCheckInterface;
use Psr\Log\LoggerInterface;

class SiteAuditor
{
    public const MAX_INTERNAL_PROBES = 1500;
    public const MAX_TWIN_PROBES     = 300;
    public const MAX_BODY_PROBES     = 80;
    public const MAX_SUB_SITEMAPS    = 25;
    public const MAX_HOST_CHECKS     = 10;
    public const SITEMAP_BYTES       = 10485760;
    public const CACHE_BUST_PARAM    = 'panth_audit_nocache';

    private const NOINDEX_PATHS_CONFIG = 'panth_robots_seo/general/noindex_paths';

    private array $checks = [];

    private array $stats = [];

    public function __construct(
        private readonly ResourceProbe $probe,
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly ?EntityLookupInterface $entityLookup = null,
        private readonly ?AuditUserAgent $userAgent = null,
        array $checks = [],
        private readonly ?SitemapLocator $sitemapLocator = null
    ) {
        foreach ($checks as $code => $check) {
            if ($check instanceof PageCheckInterface || $check instanceof SiteCheckInterface) {
                $this->checks[(string) $code] = $check;
            }
        }
    }

    public function getChecks(): array
    {
        return $this->checks;
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    public function audit(int $storeId, array $pages, array $results, array $redirectMap, bool $crawlComplete): array
    {
        $baseUrl = rtrim((string) $this->storeManager->getStore($storeId)->getBaseUrl(), '/') . '/';
        $host    = UrlHelper::host($baseUrl);
        $this->probe->configure(
            $host,
            $this->config->crawlVerifiesTls($storeId),
            $this->userAgent?->forStore($baseUrl) ?? AuditUserAgent::PRODUCT
        );

        $statuses = [];
        foreach ($results as $result) {
            if ($result instanceof CrawlResult) {
                $statuses[UrlHelper::normalize($result->url)] = $result->statusCode;
            }
        }
        $pagesByUrl = [];
        foreach ($pages as $page) {
            if ($page instanceof ParsedPage) {
                $pagesByUrl[$page->url] = $page;
            }
        }

        [$internal, $external, $scripts, $styles, $twins, $linkedHosts] = $this->collectTargets($pagesByUrl, $statuses, $host);

        $probes = $this->probe->head(array_slice($internal, 0, self::MAX_INTERNAL_PROBES), true, false);
        $probes += $this->probe->head(array_slice($twins, 0, self::MAX_TWIN_PROBES), false, false);

        $cap    = $this->config->getAuditMaxExternalChecks($storeId);
        $capped = false;
        if ($this->config->isAuditExternalLinkCheckEnabled($storeId) && $cap > 0) {
            $capped = count($external) > $cap;
            $probes += $this->probe->head(array_slice($external, 0, $cap), true, true);
        }

        $minifyJs  = $this->scopeConfig->isSetFlag('dev/js/minify_files', ScopeInterface::SCOPE_STORE, $storeId);
        $minifyCss = $this->scopeConfig->isSetFlag('dev/css/minify_files', ScopeInterface::SCOPE_STORE, $storeId);
        $bodies    = [];
        $bodyUrls  = array_merge($minifyJs ? [] : $scripts, $minifyCss ? [] : $styles);
        if ($bodyUrls !== []) {
            foreach ($this->probe->get(array_slice($bodyUrls, 0, self::MAX_BODY_PROBES), false) as $url => $result) {
                if ($result->status === 200) {
                    $bodies[$url] = $result->body;
                }
            }
        }

        $robotsProbe = $this->probe->get([$baseUrl . 'robots.txt'], false, 524288)[$baseUrl . 'robots.txt'] ?? null;
        $robots      = $robotsProbe !== null && $robotsProbe->status === 200 ? new RobotsTxt($robotsProbe->body) : null;
        $llms        = $this->probe->get([$baseUrl . 'llms.txt'], false, 614400)[$baseUrl . 'llms.txt'] ?? null;

        [$sitemapUrls, $sampled, $fetched] = $this->sitemapUrls(
            $baseUrl,
            $host,
            $robots,
            $this->config->getAuditSitemapSample($storeId),
            $storeId
        );

        $hostChecks = [];
        foreach (array_slice(array_merge([$host], $linkedHosts), 0, self::MAX_HOST_CHECKS) as $checkHost) {
            $url    = 'https://' . $checkHost . '/';
            $result = $this->probe->head([$url], true, false)[$url] ?? null;
            if ($result !== null) {
                $hostChecks[$checkHost] = $result;
            }
        }

        $pagesByUrl = $this->measureTiming($pagesByUrl, $this->config->getAuditTimingSample($storeId));

        $noindexRules = preg_split(
            '/\r\n|\r|\n|,/',
            (string) $this->scopeConfig->getValue(self::NOINDEX_PATHS_CONFIG, ScopeInterface::SCOPE_STORE, $storeId)
        ) ?: [];

        $ctx = new AuditContext(
            storeId: $storeId,
            baseUrl: $baseUrl,
            pages: $pagesByUrl,
            statuses: $statuses,
            redirectMap: $redirectMap,
            probes: $probes,
            resourceBodies: $bodies,
            robots: $robots,
            robotsStatus: $robotsProbe?->status ?? 0,
            llmsTxt: $llms,
            sitemapUrls: $sitemapUrls,
            sitemapSampled: $sampled,
            sitemapFetched: $fetched,
            hostChecks: $hostChecks,
            crawlComplete: $crawlComplete,
            slowPageMs: $this->config->getAuditSlowPageSeconds($storeId) * 1000,
            minifyJs: $minifyJs,
            minifyCss: $minifyCss,
            noindexPathRules: array_values(array_filter(array_map('trim', $noindexRules))),
            entityLookup: $this->entityLookup,
            externalChecksCapped: $capped
        );

        $issues = $this->runChecks($pagesByUrl, $ctx);

        $this->stats = [
            'internal_probes'  => min(count($internal), self::MAX_INTERNAL_PROBES),
            'external_links'   => count($external),
            'external_checked' => $cap > 0 ? min(count($external), $cap) : 0,
            'external_capped'  => $capped,
            'sitemap_urls'     => count($sitemapUrls),
            'sitemap_sampled'  => $sampled,
            'requests'         => $this->probe->getRequestCount(),
        ];

        return $issues;
    }

    public function runChecks(array $pagesByUrl, AuditContext $ctx): array
    {
        $issues = [];
        foreach ($this->checks as $code => $check) {
            try {
                if ($check instanceof PageCheckInterface) {
                    foreach ($pagesByUrl as $page) {
                        foreach ($check->check($page, $ctx) as $issue) {
                            $issues[$issue->key()] ??= $issue;
                        }
                    }
                }
                if ($check instanceof SiteCheckInterface) {
                    foreach ($check->checkSite($pagesByUrl, $ctx) as $issue) {
                        $issues[$issue->key()] ??= $issue;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('Panth SEO audit: check "%s" failed: %s', $code, $e->getMessage()));
            }
        }

        return self::sort(array_values($issues));
    }

    public static function sort(array $issues): array
    {
        usort($issues, static function (Issue $a, Issue $b): int {
            return [Issue::severityRank($b->severity), $a->code, $a->url, $a->element]
                <=> [Issue::severityRank($a->severity), $b->code, $b->url, $b->element];
        });

        return $issues;
    }

    private function collectTargets(array $pages, array $statuses, string $host): array
    {
        $internal = [];
        $external = [];
        $scripts  = [];
        $styles   = [];
        $twins    = [];
        $hosts    = [];
        $domain   = $this->registrableDomain($host);

        foreach ($pages as $page) {
            if (!$page->isOk()) {
                continue;
            }
            foreach ($page->images as $image) {
                $candidates = array_merge(
                    [(string) $image['src'], (string) $image['dataSrc'], (string) $image['linkHref']],
                    (array) $image['srcset']
                );
                foreach ($candidates as $candidate) {
                    if ($candidate !== '' && UrlHelper::sameHost($candidate, $host)) {
                        $internal[$candidate] = true;
                    }
                }
            }
            foreach ($page->scripts as $url) {
                if (UrlHelper::sameHost($url, $host)) {
                    $internal[$url] = true;
                    $scripts[$url]  = true;
                }
            }
            foreach ($page->styles as $url) {
                if (UrlHelper::sameHost($url, $host)) {
                    $internal[$url] = true;
                    $styles[$url]   = true;
                }
            }
            foreach ($page->links as $link) {
                $href = (string) $link['href'];
                if (!empty($link['isInternal'])) {
                    if (!isset($statuses[UrlHelper::normalize($href)])) {
                        $internal[$href] = true;
                    }
                    continue;
                }
                $external[$href] = true;
                $linkHost = UrlHelper::host($href);
                if ($domain !== '' && $linkHost !== $host
                    && ($linkHost === $domain || str_ends_with($linkHost, '.' . $domain))
                ) {
                    $hosts[$linkHost] = true;
                }
            }
            foreach ($page->hreflang as $entry) {
                $href = (string) $entry['href'];
                if (UrlHelper::sameHost($href, $host) && !isset($statuses[UrlHelper::normalize($href)])) {
                    $internal[$href] = true;
                }
            }
            $twin = UrlHelper::trailingSlashTwin($page->url);
            if ($twin !== null && !isset($statuses[$twin])) {
                $twins[$twin] = true;
            }
        }

        return [
            array_keys($internal),
            array_keys($external),
            array_keys($scripts),
            array_keys($styles),
            array_keys($twins),
            array_keys($hosts),
        ];
    }

    private function registrableDomain(string $host): string
    {
        $labels = explode('.', $host);
        $count  = count($labels);
        if ($count <= 2) {
            return $host;
        }
        $second = $labels[$count - 2];
        $take   = strlen($labels[$count - 1]) === 2 && in_array($second, ['co', 'com', 'org', 'net', 'ac', 'gov', 'edu'], true)
            ? 3
            : 2;

        return implode('.', array_slice($labels, -$take));
    }

    private function sitemapUrls(string $baseUrl, string $host, ?RobotsTxt $robots, int $sample, int $storeId = 0): array
    {
        $candidates = $robots !== null ? $robots->getSitemaps() : [];
        if ($candidates === []) {
            $candidates = $this->sitemapLocator !== null
                ? $this->sitemapLocator->getSitemapUrls($storeId, $baseUrl)
                : [$baseUrl . SitemapLocator::DEFAULT_FILENAME];
        }
        $urls    = [];
        $sampled = false;
        $fetched = false;
        $queue   = array_slice(array_values(array_unique($candidates)), 0, self::MAX_SUB_SITEMAPS);
        $seen    = [];

        while ($queue !== [] && count($seen) < self::MAX_SUB_SITEMAPS) {
            $sitemap = (string) array_shift($queue);
            if (isset($seen[$sitemap])) {
                continue;
            }
            $seen[$sitemap] = true;
            $result = $this->probe->get([$sitemap], true, self::SITEMAP_BYTES)[$sitemap] ?? null;
            if ($result === null || $result->status !== 200 || $result->body === '') {
                continue;
            }
            $fetched = true;
            [$type, $locs] = $this->parseSitemap($result->body);
            if ($type === 'sitemapindex') {
                foreach ($locs as $loc) {
                    $queue[] = $loc;
                }
                continue;
            }
            $locs = array_values(array_filter($locs, static fn (string $loc): bool => UrlHelper::sameHost($loc, $host)));
            if (count($locs) > $sample) {
                $sampled = true;
                $step    = count($locs) / $sample;
                $picked  = [];
                for ($i = 0; $i < $sample; $i++) {
                    $picked[] = $locs[(int) floor($i * $step)];
                }
                $locs = $picked;
            }
            foreach ($locs as $loc) {
                $urls[UrlHelper::normalize($loc)] = true;
            }
        }

        return [array_keys($urls), $sampled, $fetched];
    }

    private function parseSitemap(string $body): array
    {
        if (str_starts_with($body, "\x1f\x8b")) {
            try {
                $decoded = gzdecode($body);
            } catch (\Throwable) {
                $decoded = false;
            }
            $body = is_string($decoded) ? $decoded : '';
        }
        if ($body === '' || preg_match('~<(sitemapindex|urlset)\b~i', $body, $root) !== 1) {
            return ['', []];
        }
        preg_match_all('~<loc>\s*(.*?)\s*</loc>~is', $body, $matches);
        $locs = array_map(
            static fn (string $loc): string => trim(html_entity_decode($loc, ENT_QUOTES | ENT_XML1, 'UTF-8')),
            $matches[1] ?? []
        );

        return [strtolower($root[1]), array_values(array_filter($locs))];
    }

    private function measureTiming(array $pages, int $sample): array
    {
        $measured = 0;
        foreach ($pages as $url => $page) {
            if ($measured >= $sample) {
                break;
            }
            if (!$page->isOk() || !$page->isHtml()) {
                continue;
            }
            $bust   = $url . (str_contains($url, '?') ? '&' : '?') . self::CACHE_BUST_PARAM . '=' . random_int(100000, 999999);
            if ($measured > 0) {
                usleep(ResourceProbe::HOST_DELAY_MS * 1000);
            }
            $result = $this->probe->fetchTimed($bust);
            $measured++;
            if ($result->status >= 200 && $result->status < 300) {
                $pages[$url] = $page->withTiming($result->ttfbMs, $result->totalMs);
            }
        }

        return $pages;
    }
}
