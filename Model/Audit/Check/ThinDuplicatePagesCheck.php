<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class ThinDuplicatePagesCheck implements SiteCheckInterface
{
    public const CODE = 'thin_duplicate_pages';

    public const MAX_THIN_WORDS = 150;
    public const MIN_WORDS      = 20;

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public function checkSite(array $pages, AuditContext $ctx): array
    {
        $texts = [];
        foreach ($pages as $page) {
            if ($page instanceof ParsedPage && $page->isIndexable() && $page->mainText !== '') {
                $texts[$page->url] = mb_strtolower($page->mainText);
            }
        }

        $groups = [];
        foreach ($texts as $url => $text) {
            $body  = $this->withoutHeading($text, $pages[$url] ?? null);
            $words = str_word_count($body);
            if ($words < self::MIN_WORDS || $words > self::MAX_THIN_WORDS) {
                continue;
            }
            foreach ($texts as $hubUrl => $hubText) {
                if ($hubUrl === $url || strlen($hubText) <= strlen($text)) {
                    continue;
                }
                if (str_contains($hubText, $body)) {
                    $groups[$hubUrl][] = [$url, $words];
                    break;
                }
            }
        }

        $issues = [];
        foreach ($groups as $hubUrl => $members) {
            foreach ($members as [$url, $words]) {
                $issues[] = $this->catalog->create(
                    self::CODE,
                    $url,
                    $hubUrl,
                    sprintf(
                        'Main text (%d words) is contained in %s, which %d thin page(s) repeat. Canonicalise to the hub '
                        . 'or 301 to the hub with an anchor.',
                        $words,
                        $hubUrl,
                        count($members)
                    )
                );
            }
        }

        return $issues;
    }

    private function withoutHeading(string $text, ?ParsedPage $page): string
    {
        $heading = $page !== null ? mb_strtolower($page->h1[0] ?? '') : '';
        if ($heading !== '' && str_starts_with($text, $heading)) {
            $text = trim(mb_substr($text, mb_strlen($heading)));
        }

        return $text;
    }
}
