<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class BrokenExternalLinkCheck implements SiteCheckInterface
{
    public const CODE        = 'broken_external_link';
    public const CODE_ANCHOR = 'readme_anchor_link';

    private const README_ANCHOR = '~^https?://(www\.)?github\.com/([^/]+)/([^/]+)/raw/[^/?#]+/?(\?[^#]*)?#(.+)$~i';

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public static function readmeAnchorFix(string $href): ?string
    {
        if (preg_match(self::README_ANCHOR, $href, $m) !== 1) {
            return null;
        }

        return 'https://github.com/' . $m[2] . '/' . $m[3] . '#' . $m[5];
    }

    public function checkSite(array $pages, AuditContext $ctx): array
    {
        $targets = [];
        $issues  = [];
        foreach ($pages as $page) {
            if (!$page instanceof ParsedPage || !$page->isOk()) {
                continue;
            }
            foreach ($page->links as $link) {
                $fix = self::readmeAnchorFix((string) $link['rawHref']);
                if ($fix !== null) {
                    $issues[self::CODE_ANCHOR . $page->url . $link['rawHref']] = $this->catalog->create(
                        self::CODE_ANCHOR,
                        $page->url,
                        (string) $link['rawHref'],
                        'README anchor under /raw/ does not resolve. Use ' . $fix
                    );
                }
                if (!empty($link['isInternal'])) {
                    continue;
                }
                $targets[(string) $link['href']][$page->url] = true;
            }
        }

        foreach ($targets as $target => $linkingPages) {
            $probe = $ctx->probe($target);
            if ($probe === null || !$probe->isBroken()) {
                continue;
            }
            $reason = $probe->status === 0
                ? 'Request failed: ' . ($probe->error !== '' ? $probe->error : 'no response') . '.'
                : sprintf('Target returns HTTP %d.', $probe->status);
            foreach (array_keys($linkingPages) as $pageUrl) {
                $issues[self::CODE . $pageUrl . $target] = $this->catalog->create(
                    self::CODE,
                    $pageUrl,
                    $target,
                    sprintf('%s Linked from %d page(s).', $reason, count($linkingPages))
                );
            }
        }

        return array_values($issues);
    }
}
