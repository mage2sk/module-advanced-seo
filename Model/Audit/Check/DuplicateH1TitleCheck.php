<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class DuplicateH1TitleCheck implements PageCheckInterface
{
    public const CODE          = 'duplicate_h1_title';
    public const CODE_MULTIPLE = 'multiple_h1';
    public const CODE_MISSING  = 'missing_h1';

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public function check(ParsedPage $page, AuditContext $ctx): array
    {
        if (!$page->isIndexable()) {
            return [];
        }
        $headings = array_values(array_filter($page->h1, static fn (string $h): bool => $h !== ''));
        if ($headings === []) {
            return [$this->catalog->create(self::CODE_MISSING, $page->url, '<h1>', 'The page has no H1 heading.')];
        }
        $issues = [];
        if (count($headings) > 1) {
            $issues[] = $this->catalog->create(
                self::CODE_MULTIPLE,
                $page->url,
                '<h1>',
                sprintf('%d H1 headings: %s', count($headings), implode(' | ', array_slice($headings, 0, 5)))
            );
        }
        if ($page->title !== ''
            && DuplicateTitleCheck::normalizeTitle($headings[0]) === DuplicateTitleCheck::normalizeTitle($page->title)
        ) {
            $issues[] = $this->catalog->create(
                self::CODE,
                $page->url,
                $page->title,
                'The H1 and the <title> are identical.'
            );
        }

        return $issues;
    }
}
