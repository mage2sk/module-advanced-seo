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

    public function testPagingIsStillFollowed(): void
    {
        $this->assertSame(
            ['https://example.com/gear/bags.html?p=2'],
            $this->links(self::anchor('/gear/bags.html?p=2'))
        );
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
}
