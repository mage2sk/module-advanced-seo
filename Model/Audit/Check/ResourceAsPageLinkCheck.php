<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;
use Panth\AdvancedSEO\Model\Audit\UrlHelper;

class ResourceAsPageLinkCheck implements PageCheckInterface
{
    public const CODE = 'resource_as_page_link';

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public function check(ParsedPage $page, AuditContext $ctx): array
    {
        if (!$page->isOk()) {
            return [];
        }
        $issues = [];
        foreach ($page->links as $link) {
            $href = (string) $link['href'];
            if (empty($link['isInternal']) || !UrlHelper::isResource($href)) {
                continue;
            }
            $status = $ctx->statusOf($href);
            $issues[$href] = $this->catalog->create(
                self::CODE,
                $page->url,
                (string) $link['rawHref'],
                sprintf(
                    '<a href> points to a .%s file%s%s.',
                    UrlHelper::extension($href),
                    UrlHelper::isRelative((string) $link['rawHref']) ? ' through a relative path (' . $href . ')' : '',
                    $status !== null ? ', HTTP ' . $status : ''
                )
            );
        }

        return array_values($issues);
    }
}
