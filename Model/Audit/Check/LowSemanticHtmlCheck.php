<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class LowSemanticHtmlCheck implements PageCheckInterface
{
    public const CODE      = 'low_semantic_html';
    public const CODE_MAIN = 'duplicate_main_landmark';

    public const MAX_DIV_SPAN_SHARE = 0.6;

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public function check(ParsedPage $page, AuditContext $ctx): array
    {
        if (!$page->isOk() || !$page->isHtml() || $page->textBytes === 0) {
            return [];
        }
        $issues = [];
        if (array_sum($page->landmarks) === 0) {
            $issues[] = $this->catalog->create(
                self::CODE,
                $page->url,
                'landmarks',
                'The page has none of main, article, nav, header, footer or section.'
            );
        } elseif ($page->divSpanTextShare > self::MAX_DIV_SPAN_SHARE) {
            $issues[] = $this->catalog->create(
                self::CODE,
                $page->url,
                'div/span text',
                sprintf('%d%% of the visible text sits inside div/span only.', (int) round($page->divSpanTextShare * 100))
            );
        }
        if (($page->landmarks['main'] ?? 0) > 1) {
            $issues[] = $this->catalog->create(
                self::CODE_MAIN,
                $page->url,
                '<main>',
                sprintf('%d main landmarks (<main> or role="main").', $page->landmarks['main'])
            );
        }

        return $issues;
    }
}
