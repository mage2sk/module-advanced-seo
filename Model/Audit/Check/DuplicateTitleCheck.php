<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class DuplicateTitleCheck implements SiteCheckInterface
{
    public const CODE                = 'duplicate_title';
    public const CODE_CANNIBALISATION = 'possible_cannibalisation';

    public const JACCARD_THRESHOLD = 0.8;

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public static function normalizeTitle(string $title): string
    {
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($title)));

        return is_string($normalized) ? $normalized : '';
    }

    public static function jaccard(string $a, string $b): float
    {
        $setA = array_unique(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($a), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $setB = array_unique(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($b), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($setA === [] || $setB === []) {
            return 0.0;
        }
        $union = count(array_unique(array_merge($setA, $setB)));

        return $union === 0 ? 0.0 : count(array_intersect($setA, $setB)) / $union;
    }

    public function checkSite(array $pages, AuditContext $ctx): array
    {
        $groups = [];
        foreach ($pages as $page) {
            if (!$page instanceof ParsedPage || !$page->isIndexable() || $page->title === '') {
                continue;
            }
            $groups[self::normalizeTitle($page->title)][] = $page;
        }

        $issues = [];
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            $urls = array_map(static fn (ParsedPage $p): string => $p->url, $group);
            foreach ($group as $page) {
                $others = array_values(array_diff($urls, [$page->url]));
                $issues[] = $this->catalog->create(
                    self::CODE,
                    $page->url,
                    $page->title,
                    sprintf(
                        'Title shared by %d pages. Also used by: %s',
                        count($group),
                        implode(', ', array_slice($others, 0, 10)) . (count($others) > 10 ? ', ...' : '')
                    )
                );
            }
            $count = count($group);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $h1a = $group[$i]->h1[0] ?? '';
                    $h1b = $group[$j]->h1[0] ?? '';
                    $score = self::jaccard($h1a, $h1b);
                    if ($score < self::JACCARD_THRESHOLD) {
                        continue;
                    }
                    foreach ([[$group[$i], $group[$j]], [$group[$j], $group[$i]]] as [$page, $other]) {
                        $issues[] = $this->catalog->create(
                            self::CODE_CANNIBALISATION,
                            $page->url,
                            $page->h1[0] ?? '',
                            sprintf(
                                'Same title and near-identical H1 (Jaccard %.2f) as %s.',
                                $score,
                                $other->url
                            )
                        );
                    }
                }
            }
        }

        return $issues;
    }
}
