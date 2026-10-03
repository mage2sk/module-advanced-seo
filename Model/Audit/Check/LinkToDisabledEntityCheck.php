<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class LinkToDisabledEntityCheck implements SiteCheckInterface
{
    public const CODE = 'link_to_disabled_entity';

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public function checkSite(array $pages, AuditContext $ctx): array
    {
        if ($ctx->entityLookup === null) {
            return [];
        }
        $targets = [];
        foreach ($pages as $page) {
            if (!$page instanceof ParsedPage || !$page->isOk()) {
                continue;
            }
            foreach ($page->links as $link) {
                if (empty($link['isInternal'])) {
                    continue;
                }
                $status = $ctx->statusOf((string) $link['href']);
                if ($status === 302 || $status === 404 || $status === 410) {
                    $targets[(string) $link['href']][$page->url] = $status;
                }
            }
        }

        $issues = [];
        foreach ($targets as $target => $linkingPages) {
            try {
                $entity = $ctx->entityLookup->entityForUrl($target, $ctx->storeId);
                if ($entity === null || !empty($entity['active'])) {
                    continue;
                }
                $sources = $ctx->entityLookup->sourcesLinkingTo($target, $entity, $ctx->storeId);
            } catch (\Throwable) {
                continue;
            }
            foreach ($linkingPages as $pageUrl => $status) {
                $issues[] = $this->catalog->create(
                    self::CODE,
                    $pageUrl,
                    $target,
                    sprintf(
                        'Links to disabled %s #%d (HTTP %d) from %d page(s).%s',
                        $entity['type'],
                        $entity['id'],
                        $status,
                        count($linkingPages),
                        $sources !== [] ? ' Sources: ' . implode('; ', array_slice($sources, 0, 5)) . '.' : ''
                    )
                );
            }
        }

        return $issues;
    }
}
