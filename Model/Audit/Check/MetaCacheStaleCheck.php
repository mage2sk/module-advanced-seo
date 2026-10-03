<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;
use Panth\AdvancedSEO\Model\Audit\UrlHelper;

class MetaCacheStaleCheck implements SiteCheckInterface
{
    public const CODE = 'meta_cache_stale';

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public function checkSite(array $pages, AuditContext $ctx): array
    {
        if ($ctx->entityLookup === null) {
            return [];
        }
        $issues = [];
        foreach ($pages as $page) {
            if (!$page instanceof ParsedPage || !$page->isIndexable() || $page->title === ''
                || UrlHelper::query($page->url) !== ''
            ) {
                continue;
            }
            try {
                $resolved = $ctx->entityLookup->resolvedTitle($page->url, $ctx->storeId);
            } catch (\Throwable) {
                continue;
            }
            if ($resolved === null || trim($resolved) === '') {
                continue;
            }
            $rendered = DuplicateTitleCheck::normalizeTitle($page->title);
            $expected = DuplicateTitleCheck::normalizeTitle(html_entity_decode($resolved, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (str_contains($rendered, $expected)) {
                continue;
            }
            $issues[] = $this->catalog->create(
                self::CODE,
                $page->url,
                '<title>',
                sprintf('Rendered title "%s" differs from the resolved meta title "%s".', $page->title, $resolved)
            );
        }

        return $issues;
    }
}
