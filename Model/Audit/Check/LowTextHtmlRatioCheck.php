<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class LowTextHtmlRatioCheck implements PageCheckInterface
{
    public const CODE = 'low_text_html_ratio';

    public const MIN_RATIO = 0.10;

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public function check(ParsedPage $page, AuditContext $ctx): array
    {
        if (!$page->isOk() || !$page->isHtml() || $page->htmlBytes === 0) {
            return [];
        }
        $ratio = $page->textBytes / $page->htmlBytes;
        if ($ratio >= self::MIN_RATIO) {
            return [];
        }
        $contributors = $page->inlineBytes;
        arsort($contributors);
        $parts = [];
        foreach ($contributors as $kind => $bytes) {
            if ($bytes > 0) {
                $parts[] = sprintf('inline %s %d KB', $kind, (int) round($bytes / 1024));
            }
        }

        return [$this->catalog->create(
            self::CODE,
            $page->url,
            sprintf('text/HTML ratio %.1f%%', $ratio * 100),
            sprintf(
                'Visible text %d KB of %d KB HTML. Top contributors: %s.',
                (int) round($page->textBytes / 1024),
                (int) round($page->htmlBytes / 1024),
                $parts !== [] ? implode(', ', $parts) : 'markup'
            )
        )];
    }
}
