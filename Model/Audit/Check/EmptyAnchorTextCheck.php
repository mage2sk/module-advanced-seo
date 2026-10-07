<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class EmptyAnchorTextCheck implements PageCheckInterface
{
    public const CODE = 'empty_anchor_text';

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
            if (($link['anchorText'] ?? '') !== ''
                || ($link['ariaLabel'] ?? '') !== ''
                || ($link['titleAttr'] ?? '') !== ''
                || ($link['imgAlt'] ?? '') !== ''
            ) {
                continue;
            }
            $issues[$link['href']] = $this->catalog->create(
                self::CODE,
                $page->url,
                (string) $link['href'],
                'Link has no text, aria-label, title or image alt text.'
            );
        }

        return array_values($issues);
    }
}
