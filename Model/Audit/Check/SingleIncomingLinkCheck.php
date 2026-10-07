<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;
use Panth\AdvancedSEO\Model\Audit\UrlHelper;

class SingleIncomingLinkCheck implements SiteCheckInterface
{
    public const CODE                = 'single_incoming_link';
    public const CODE_ORPHAN         = 'orphan_in_sitemap';
    public const CODE_TRAILING_TWIN  = 'duplicate_trailing_slash';

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public static function incomingGraph(array $pages, ?AuditContext $ctx = null): array
    {
        $incoming = [];
        foreach ($pages as $page) {
            if (!$page instanceof ParsedPage || !$page->isOk()) {
                continue;
            }
            $source = UrlHelper::normalize($page->url);
            foreach ($page->links as $link) {
                if (empty($link['isInternal'])) {
                    continue;
                }
                $target = UrlHelper::normalize((string) $link['href']);
                $final  = $ctx !== null ? $ctx->finalUrlOf($target) : '';
                foreach (array_filter([$target, $final !== '' ? UrlHelper::normalize($final) : '']) as $node) {
                    if ($node !== $source) {
                        $incoming[$node][$source] = true;
                    }
                }
            }
        }

        return $incoming;
    }

    public function checkSite(array $pages, AuditContext $ctx): array
    {
        $incoming = self::incomingGraph($pages, $ctx);
        $home     = UrlHelper::normalize(rtrim($ctx->baseUrl, '/') . '/');
        $issues    = [];
        $twinPairs = [];

        foreach ($pages as $page) {
            if (!$page instanceof ParsedPage || !$page->isOk()) {
                continue;
            }
            $url = UrlHelper::normalize($page->url);

            $twin = UrlHelper::trailingSlashTwin($url);
            $pair = $twin !== null ? min($url, $twin) . '|' . max($url, $twin) : '';
            if ($twin !== null && !isset($twinPairs[$pair]) && $ctx->statusOf($twin) === 200) {
                $twinPairs[$pair] = true;
                $issues[] = $this->catalog->create(
                    self::CODE_TRAILING_TWIN,
                    $page->url,
                    $twin,
                    'Both ' . $page->url . ' and ' . $twin . ' return 200. 301-redirect the slash variant.'
                );
            }

            if ($url === $home || !$page->isIndexable()) {
                continue;
            }
            $sources = array_keys($incoming[$url] ?? []);
            if (count($sources) === 1) {
                $issues[] = $this->catalog->create(
                    self::CODE,
                    $page->url,
                    $sources[0],
                    'Only one crawled page links here: ' . $sources[0]
                );
            }
        }

        if ($ctx->crawlComplete && $ctx->sitemapUrls !== []) {
            foreach ($ctx->sitemapUrls as $sitemapUrl) {
                $url = UrlHelper::normalize((string) $sitemapUrl);
                if ($url === $home || isset($incoming[$url])) {
                    continue;
                }
                $issues[] = $this->catalog->create(
                    self::CODE_ORPHAN,
                    $url,
                    'sitemap.xml',
                    'Listed in the XML sitemap but no crawled page links to it'
                    . ($ctx->sitemapSampled ? ' (sitemap sampled).' : '.')
                );
            }
        }

        return $issues;
    }
}
