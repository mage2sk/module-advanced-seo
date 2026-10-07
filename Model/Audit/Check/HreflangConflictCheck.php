<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;
use Panth\AdvancedSEO\Model\Audit\UrlHelper;

class HreflangConflictCheck implements PageCheckInterface, SiteCheckInterface
{
    public const CODE = 'hreflang_conflict';

    private const LOCALE_PATTERN = '/^(x-default|[a-z]{2}(-[a-z]{2})?)$/i';

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public static function isValidLocale(string $locale): bool
    {
        return preg_match(self::LOCALE_PATTERN, $locale) === 1;
    }

    public function check(ParsedPage $page, AuditContext $ctx): array
    {
        if (!$page->isOk() || $page->hreflang === []) {
            return [];
        }
        $issues   = [];
        $self     = UrlHelper::normalize($page->url);
        $selfAlt  = $page->canonical !== '' ? UrlHelper::normalize($page->canonical) : $self;
        $byLocale = [];
        $hasSelf  = false;
        $hasLang  = false;

        foreach ($page->hreflang as $entry) {
            $locale = (string) $entry['locale'];
            $href   = UrlHelper::normalize((string) $entry['href']);
            if (!self::isValidLocale($locale)) {
                $issues['invalid' . $locale] = $this->catalog->create(
                    self::CODE,
                    $page->url,
                    'hreflang=' . $locale,
                    sprintf('"%s" is not an ISO 639-1 language code with an optional ISO 3166-1 region.', $locale)
                );
            }
            $key = strtolower($locale);
            $byLocale[$key][$href] = true;
            if ($key !== 'x-default') {
                $hasLang = true;
                if ($href === $self || $href === $selfAlt) {
                    $hasSelf = true;
                }
            }

            $status = $ctx->statusOf($href);
            if ($status !== null && $status !== 200) {
                $issues['status' . $href] = $this->catalog->create(
                    self::CODE,
                    $page->url,
                    'hreflang=' . $locale . ' ' . $href,
                    sprintf('Alternate URL returns HTTP %d; hreflang must point to 200 pages.', $status)
                );
                continue;
            }
            $target = $ctx->page($href);
            if ($target !== null && $target->canonical !== ''
                && UrlHelper::normalize($target->canonical) !== $href
            ) {
                $issues['canonical' . $href] = $this->catalog->create(
                    self::CODE,
                    $page->url,
                    'hreflang=' . $locale . ' ' . $href,
                    sprintf('Alternate URL is not canonical; its canonical is %s.', $target->canonical)
                );
            }
        }

        foreach ($byLocale as $locale => $hrefs) {
            if (count($hrefs) > 1) {
                $issues['dup' . $locale] = $this->catalog->create(
                    self::CODE,
                    $page->url,
                    'hreflang=' . $locale,
                    sprintf(
                        'Locale %s is declared %d times with different URLs (%s). One page per locale per group.',
                        $locale,
                        count($hrefs),
                        implode(', ', array_slice(array_keys($hrefs), 0, 5)) . (count($hrefs) > 5 ? ', ...' : '')
                    )
                );
            }
        }

        if ($hasLang && !$hasSelf) {
            $issues['self'] = $this->catalog->create(
                self::CODE,
                $page->url,
                'hreflang self-reference',
                'The page lists alternates but no hreflang entry points back to the page itself.'
            );
        }

        return array_values($issues);
    }

    public function checkSite(array $pages, AuditContext $ctx): array
    {
        $map = [];
        foreach ($pages as $page) {
            if (!$page instanceof ParsedPage || !$page->isOk() || $page->hreflang === []) {
                continue;
            }
            $url = UrlHelper::normalize($page->url);
            foreach ($page->hreflang as $entry) {
                $href = UrlHelper::normalize((string) $entry['href']);
                if ($href !== $url) {
                    $map[$url][$href][] = strtolower((string) $entry['locale']);
                }
            }
        }

        $issues   = [];
        $reported = [];
        foreach ($map as $source => $targets) {
            foreach ($targets as $target => $locales) {
                if (!isset($map[$target]) && $ctx->page($target) === null) {
                    continue;
                }
                $back = $map[$target][$source] ?? null;
                if ($back === null) {
                    $issues[] = $this->catalog->create(
                        self::CODE,
                        $source,
                        'hreflang=' . $locales[0] . ' ' . $target,
                        'Missing return link: the alternate page does not list this page in its hreflang tags.'
                    );
                    continue;
                }
                $shared = array_diff(array_intersect($locales, $back), ['x-default']);
                $pair   = $source < $target ? $source . '|' . $target : $target . '|' . $source;
                if ($shared !== [] && !isset($reported[$pair])) {
                    $reported[$pair] = true;
                    $issues[] = $this->catalog->create(
                        self::CODE,
                        $source,
                        'hreflang=' . reset($shared) . ' ' . $target,
                        sprintf(
                            'Both pages declare each other as %s. Two different pages cannot be the same locale '
                            . 'alternate; hreflang groups are not topic clusters.',
                            reset($shared)
                        )
                    );
                }
            }
        }

        return $issues;
    }
}
