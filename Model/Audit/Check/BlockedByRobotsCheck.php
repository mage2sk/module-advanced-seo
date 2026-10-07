<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\Issue;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;
use Panth\AdvancedSEO\Model\Audit\UrlHelper;

class BlockedByRobotsCheck implements PageCheckInterface
{
    public const CODE       = 'blocked_by_robots';
    public const CODE_QUERY = 'query_param_noindex';

    private const EXPECTED_PATH  = '~^/(checkout|cart|customer|catalogsearch|search|wishlist|sales|review/customer|catalog/product_compare)(/|$|\.)~i';
    private const IMPORTANT_PATH = '~(contact|about|service|pricing|faq)~i';

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public static function isExpectedPath(string $path): bool
    {
        return preg_match(self::EXPECTED_PATH, $path) === 1;
    }

    public function check(ParsedPage $page, AuditContext $ctx): array
    {
        if ($page->status !== 200) {
            return [];
        }
        $path   = UrlHelper::path($page->url);
        $query  = UrlHelper::query($page->url);
        $issues = [];

        $pathWithQuery = $path . ($query !== '' ? '?' . $query : '');
        if ($ctx->robots !== null && !$ctx->robots->isAllowed('*', $pathWithQuery)) {
            $issues[] = $this->issue($page, $ctx, $path, 'robots.txt Disallow: ' . $ctx->robots->matchingDisallow('*', $pathWithQuery));
        }
        if (stripos($page->robotsMeta, 'noindex') !== false) {
            $issues[] = $query !== '' && !self::isExpectedPath($path)
                ? $this->catalog->create(
                    self::CODE_QUERY,
                    $page->url,
                    'meta robots: ' . $page->robotsMeta,
                    'URL with query parameters is noindex,follow (correct for unknown parameters).'
                )
                : $this->issue($page, $ctx, $path, 'meta robots: ' . $page->robotsMeta);
        }
        if (stripos($page->xRobotsTag, 'noindex') !== false) {
            $issues[] = $this->issue($page, $ctx, $path, 'X-Robots-Tag: ' . $page->xRobotsTag);
        }

        return $issues;
    }

    private function issue(ParsedPage $page, AuditContext $ctx, string $path, string $element): Issue
    {
        $rule = '';
        foreach ($ctx->noindexPathRules as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && (fnmatch($candidate, $path) || str_starts_with($path, rtrim($candidate, '*')))) {
                $rule = $candidate;
                break;
            }
        }
        if (self::isExpectedPath($path)) {
            return $this->catalog->create(self::CODE, $page->url, $element, 'Expected: private or search page.');
        }
        if (preg_match(self::IMPORTANT_PATH, $path) === 1) {
            return $this->catalog->create(
                self::CODE,
                $page->url,
                $element,
                $rule !== ''
                    ? sprintf('Important page is noindexed by the path rule "%s" (panth_robots_seo/general/noindex_paths).', $rule)
                    : 'Important page is blocked. Check robots.txt, the page robots setting and the path rules in '
                        . 'panth_robots_seo/general/noindex_paths.',
                Issue::SEVERITY_WARNING
            );
        }

        return $this->catalog->create(self::CODE, $page->url, $element, 'Page is blocked from crawling or indexing.');
    }
}
