<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;
use Panth\AdvancedSEO\Model\Audit\UrlHelper;

class NofollowInternalLinkCheck implements PageCheckInterface
{
    public const CODE         = 'nofollow_internal_link';
    public const CODE_ALLOWED = 'nofollow_internal_link_allowed';

    private const ALLOWED_PATH = '~/(cart|checkout|customer|account|wishlist|compare|login|sales|review/customer)(/|$|\.)~i';

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public static function allowedReason(array $link): string
    {
        $href = (string) $link['href'];
        if (preg_match(self::ALLOWED_PATH, UrlHelper::path($href)) === 1) {
            return 'account, cart or checkout link';
        }
        if (UrlHelper::query($href) !== '') {
            return 'filter, sort or paging URL';
        }
        if (!empty($link['inFilterNav'])) {
            return 'layered-navigation link';
        }

        return '';
    }

    public function check(ParsedPage $page, AuditContext $ctx): array
    {
        if (!$page->isOk()) {
            return [];
        }
        $issues = [];
        foreach ($page->links as $link) {
            if (empty($link['isInternal']) || !$this->hasToken((string) $link['rel'], 'nofollow')) {
                continue;
            }
            $reason = self::allowedReason($link);
            $code   = $reason === '' ? self::CODE : self::CODE_ALLOWED;
            $issues[$code . $link['href']] = $this->catalog->create(
                $code,
                $page->url,
                (string) $link['href'],
                $reason === ''
                    ? 'Internal link carries rel="' . $link['rel'] . '".'
                    : 'Expected nofollow: ' . $reason . '.'
            );
        }

        return array_values($issues);
    }

    private function hasToken(string $rel, string $token): bool
    {
        return in_array($token, preg_split('/\s+/', strtolower($rel)) ?: [], true);
    }
}
