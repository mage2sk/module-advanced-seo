<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class NofollowExternalLinkCheck implements PageCheckInterface
{
    public const CODE = 'nofollow_external_link';

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
            if (!empty($link['isInternal'])) {
                continue;
            }
            $tokens = preg_split('/\s+/', strtolower((string) $link['rel'])) ?: [];
            if (!in_array('nofollow', $tokens, true)) {
                continue;
            }
            if (array_intersect($tokens, ['sponsored', 'ugc']) !== []) {
                continue;
            }
            $issues[$link['href']] = $this->catalog->create(
                self::CODE,
                $page->url,
                (string) $link['href'],
                'External link with rel="' . $link['rel'] . '". Links marked sponsored or ugc are not counted.'
            );
        }

        return array_values($issues);
    }
}
