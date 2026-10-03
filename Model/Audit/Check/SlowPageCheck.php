<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class SlowPageCheck implements PageCheckInterface
{
    public const CODE       = 'slow_page';
    public const CODE_LARGE = 'large_html';

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public function check(ParsedPage $page, AuditContext $ctx): array
    {
        if (!$page->isOk() || !$page->isHtml()) {
            return [];
        }
        $issues = [];
        $timing = sprintf(
            'Total %.2f s, TTFB %.2f s, HTML %d KB%s.',
            $page->totalMs / 1000,
            $page->ttfbMs / 1000,
            (int) round($page->htmlBytes / 1024),
            $page->timingCacheBusted ? ', cache-busted fetch' : ''
        );
        if ($page->totalMs > $ctx->slowPageMs) {
            $issues[] = $this->catalog->create(
                self::CODE,
                $page->url,
                'HTML load time',
                sprintf('%s Threshold %.1f s.', $timing, $ctx->slowPageMs / 1000)
            );
        }
        if ($page->htmlBytes > $ctx->largeHtmlBytes) {
            $issues[] = $this->catalog->create(
                self::CODE_LARGE,
                $page->url,
                'HTML size',
                sprintf(
                    'HTML is %d KB (limit %d KB). Inline style %d KB, inline script %d KB, SVG %d KB.',
                    (int) round($page->htmlBytes / 1024),
                    (int) round($ctx->largeHtmlBytes / 1024),
                    (int) round(($page->inlineBytes['style'] ?? 0) / 1024),
                    (int) round(($page->inlineBytes['script'] ?? 0) / 1024),
                    (int) round(($page->inlineBytes['svg'] ?? 0) / 1024)
                )
            );
        }

        return $issues;
    }
}
