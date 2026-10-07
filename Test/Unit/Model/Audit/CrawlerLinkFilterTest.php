<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\Crawler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CrawlerLinkFilterTest extends TestCase
{
    private const EXCLUDES = "/customer/*\n/checkout\n/checkout/*\n/wishlist/*\n/catalogsearch/*";

    private function crawler(string $excludePaths = self::EXCLUDES, bool $followFiltered = false): array
    {
        $config = $this->createStub(Config::class);
        $config->method('getCrawlExcludePaths')->willReturn($excludePaths);
        $config->method('crawlFollowsFilteredUrls')->willReturn($followFiltered);

        $crawler = new Crawler(
            $this->createStub(CurlFactory::class),
            $this->createStub(StoreManagerInterface::class),
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $reflection = new \ReflectionClass($crawler);
        $extract = $reflection->getMethod('extractLinks');
        $extract->setAccessible(true);
        $patterns = $reflection->getMethod('getExcludePatterns');
        $patterns->setAccessible(true);

        return [$crawler, $extract, $patterns->invoke($crawler, 1), $followFiltered];
    }

    private function links(string $html, string $excludePaths = self::EXCLUDES, bool $followFiltered = false): array
    {
        [$crawler, $extract, $patterns, $follow] = $this->crawler($excludePaths, $followFiltered);

        return $extract->invoke(
            $crawler,
            $html,
            'https://example.com/',
            'example.com',
            'https://example.com',
            $patterns,
            $follow
        );
    }

    private static function anchor(string $href): string
    {
        return '<a href="' . $href . '">link</a>';
    }

    #[DataProvider('excludedProvider')]
    public function testPrivatePathsAreNotQueued(string $href): void
    {
        $this->assertSame([], $this->links(self::anchor($href)), $href . ' should be excluded');
    }

    public static function excludedProvider(): array
    {
        return [
            'customer account' => ['/customer/account/index/'],
            'customer login with base64 referer' => ['/customer/account/login/referer/aHR0cHM6Ly9leGFtcGxlLmNvbS8/'],
            'checkout root' => ['/checkout/'],
            'checkout cart' => ['/checkout/cart/'],
            'wishlist' => ['/wishlist/index/index/'],
            'catalogsearch' => ['/catalogsearch/result/?q=bag'],
        ];
    }

    #[DataProvider('filteredProvider')]
    public function testLayeredNavigationUrlsAreNotQueued(string $href): void
    {
        $this->assertSame([], $this->links(self::anchor($href)), $href . ' should be skipped');
    }

    public static function filteredProvider(): array
    {
        return [
            'attribute filter' => ['/gear/bags.html?price=20-30'],
            'two filters' => ['/gear/bags.html?color=5&size=M'],
            'sort order' => ['/gear/bags.html?product_list_order=price'],
            'page size' => ['/gear/bags.html?product_list_limit=36'],
        ];
    }

    #[DataProvider('queryUrlProvider')]
    public function testAnyQueryStringIsSkipped(string $href): void
    {
        $this->assertSame([], $this->links(self::anchor($href)), $href . ' should not be queued');
    }

    public static function queryUrlProvider(): array
    {
        return [
            'paging' => ['/gear/bags.html?p=2'],
            'attribute filter' => ['/gear/bags.html?price=20-30'],
            'sort' => ['/gear/bags.html?product_list_order=price'],
            'tracking parameter' => ['/gear/bags.html?utm_source=news'],
            'empty-valued parameter' => ['/gear/bags.html?foo='],
        ];
    }

    public function testQueryUrlsComeBackWhenFollowingIsTurnedOn(): void
    {
        $links = $this->links(self::anchor('/gear/bags.html?p=2'), self::EXCLUDES, true);

        $this->assertSame(['https://example.com/gear/bags.html?p=2'], array_values($links));
    }

    public function testContentPagesAreStillFollowed(): void
    {
        $html = self::anchor('/gear/bags.html')
            . self::anchor('/compete-track-tote.html')
            . self::anchor('/about-us');

        $this->assertSame(
            [
                'https://example.com/gear/bags.html',
                'https://example.com/compete-track-tote.html',
                'https://example.com/about-us',
            ],
            array_values($this->links($html))
        );
    }

    public function testFollowingFilteredUrlsCanBeTurnedBackOn(): void
    {
        $links = $this->links(self::anchor('/gear/bags.html?price=20-30'), self::EXCLUDES, true);

        $this->assertSame(['https://example.com/gear/bags.html?price=20-30'], array_values($links));
    }

    public function testClearingTheExcludeListCrawlsEverything(): void
    {
        $links = $this->links(self::anchor('/customer/account/index/'), '');

        $this->assertSame(['https://example.com/customer/account/index/'], array_values($links));
    }

    public function testExistingFiltersStillApply(): void
    {
        $html = self::anchor('/logo.png')
            . '<a href="/private" rel="nofollow">no</a>'
            . self::anchor('https://other-domain.example/page')
            . self::anchor('mailto:someone@example.com')
            . self::anchor('/keep-me.html');

        $this->assertSame(['https://example.com/keep-me.html'], array_values($this->links($html)));
    }

    #[DataProvider('nonHttpSchemeProvider')]
    public function testOnlyHttpSchemesAreCrawled(string $href): void
    {
        $this->assertSame([], $this->links(self::anchor($href)), $href . ' is not a page');
    }

    public static function nonHttpSchemeProvider(): array
    {
        return [
            'tel' => ['tel:01482653790'],
            'tel with spaces' => ['tel:+44 1482 653790'],
            'sms with a numeric path' => ['sms:12345'],
            'callto' => ['callto:12345'],
            'whatsapp' => ['whatsapp://send?phone=123'],
            'skype' => ['skype:live.someone?call'],
            'data uri' => ['data:text/html;base64,PGh0bWw+'],
            'mailto' => ['mailto:someone@example.com'],
            'javascript' => ['javascript:void(0)'],
            'ftp' => ['ftp://files.example.com/x'],
        ];
    }

    public function testProtocolRelativeAndRelativeLinksAreStillFollowed(): void
    {
        $this->assertSame(
            ['https://example.com/keep.html'],
            array_values($this->links(self::anchor('/keep.html')))
        );

        $this->assertSame(
            ['https://example.com/proto.html'],
            array_values($this->links(self::anchor('//example.com/proto.html')))
        );
    }

    public function testCdnCgiIsExcludedByDefaultList(): void
    {
        $excludes = self::EXCLUDES . "\n/cdn-cgi/*";

        $this->assertSame([], $this->links(self::anchor('/cdn-cgi/l/email-protection'), $excludes));
    }

    #[DataProvider('nonRenderedRegionProvider')]
    public function testLinksInsideNonRenderedMarkupAreNotQueued(string $html): void
    {
        $this->assertSame([], $this->links($html), $html . ' is not rendered markup');
    }

    public static function nonRenderedRegionProvider(): array
    {
        $anchor = self::anchor('/item.product_url');

        return [
            'knockout template script' => ['<script type="text/x-magento-template">' . $anchor . '</script>'],
            'plain script' => ['<script>var tpl = \'' . $anchor . '\';</script>'],
            'template element' => ['<template x-for="item in items">' . $anchor . '</template>'],
            'noscript' => ['<noscript>' . $anchor . '</noscript>'],
            'html comment' => ['<!-- ' . $anchor . ' -->'],
            'uppercase script tag' => ['<SCRIPT TYPE="text/x-magento-template">' . $anchor . '</SCRIPT>'],
            'script with a trailing space in the close tag' => ['<script>' . $anchor . '</script >'],
        ];
    }

    #[DataProvider('templatePlaceholderProvider')]
    public function testUnrenderedPlaceholderHrefsAreNotQueued(string $href): void
    {
        $this->assertSame([], $this->links(self::anchor($href)), $href . ' is not a real url');
    }

    public static function templatePlaceholderProvider(): array
    {
        return [
            'magento template token' => ['/catalog/{{product.url}}'],
            'js interpolation' => ['/p/${id}.html'],
            'underscore template' => ['/p/<%= slug %>.html'],
            'ruby or alpine style' => ['/p/#{slug}.html'],
            'double bracket' => ['/p/[[slug]].html'],
        ];
    }

    public function testRealLinksAroundAScriptBlockSurvive(): void
    {
        $html = self::anchor('/before.html')
            . '<script type="text/x-magento-template">' . self::anchor('/item.product_url') . '</script>'
            . self::anchor('/after.html');

        $this->assertSame(
            ['https://example.com/before.html', 'https://example.com/after.html'],
            array_values($this->links($html))
        );
    }

    #[DataProvider('boundAttributeProvider')]
    public function testFrameworkBoundHrefAttributesAreNotLinks(string $tag): void
    {
        $this->assertSame([], $this->links($tag), $tag . ' is a binding, not an href');
    }

    public static function boundAttributeProvider(): array
    {
        return [
            'alpine shorthand'  => ['<a :href="item.configure_url" class="btn">configure</a>'],
            'alpine x-bind'     => ['<a x-bind:href="item.configure_url">configure</a>'],
            'vue v-bind'        => ['<a v-bind:href="item.url">go</a>'],
            'angular bracket'   => ['<a [href]="item.url">go</a>'],
            'data attribute'    => ['<a data-href="/catalog/thing.html">go</a>'],
            'knockout bind'     => ['<a data-bind="attr: {href: item.url}">go</a>'],
        ];
    }

    public function testARealHrefIsStillFoundWhateverTheWhitespace(): void
    {
        $html = "<a\n    class=\"btn\"\n    href=\"/keep.html\"\n    rel=\"noopener\">keep</a>";

        $this->assertSame(['https://example.com/keep.html'], array_values($this->links($html)));
    }

    public function testABoundAndARealHrefOnTheSameTagKeepOnlyTheRealOne(): void
    {
        $html = '<a :href="item.configure_url" href="/real.html">both</a>';

        $this->assertSame(['https://example.com/real.html'], array_values($this->links($html)));
    }

    public function testNestedTemplatesAreStrippedWhole(): void
    {
        $html = '<template x-for="item in items">'
            . '<template x-if="item.options"><dl><dd>x</dd></dl></template>'
            . self::anchor('/inside-outer-template.html')
            . '</template>'
            . self::anchor('/after.html');

        $this->assertSame(['https://example.com/after.html'], array_values($this->links($html)));
    }

    public function testDeeplyNestedTemplatesDoNotLeakLinks(): void
    {
        $html = '<template x-for="a in b"><template x-if="c"><template x-for="d in e">'
            . self::anchor('/deep.html')
            . '</template></template></template>'
            . self::anchor('/kept.html');

        $this->assertSame(['https://example.com/kept.html'], array_values($this->links($html)));
    }

    public function testADottedPathInRenderedMarkupIsStillReported(): void
    {
        $this->assertSame(
            ['https://example.com/item.product_url'],
            array_values($this->links(self::anchor('/item.product_url')))
        );
    }
}
