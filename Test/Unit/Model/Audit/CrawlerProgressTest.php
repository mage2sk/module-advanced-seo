<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\Crawler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CrawlerProgressTest extends TestCase
{
    private const BASE = 'https://example.com';

    private function page(int $index): string
    {
        $next = $index + 1;

        return '<html><head><title>Page ' . $index . '</title></head>'
            . '<body><a href="' . self::BASE . '/p' . $next . '">next</a></body></html>';
    }

    private function crawler(): Crawler
    {
        $fetched = 0;

        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturnCallback(function () use (&$fetched): string {
            return $this->page(++$fetched);
        });

        $curlFactory = $this->createStub(CurlFactory::class);
        $curlFactory->method('create')->willReturn($curl);

        $store = $this->createStub(\Magento\Store\Model\Store::class);
        $store->method('getBaseUrl')->willReturn(self::BASE . '/');

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $config = $this->createStub(Config::class);
        $config->method('getCrawlExcludePaths')->willReturn('');
        $config->method('crawlFollowsFilteredUrls')->willReturn(false);
        $config->method('crawlVerifiesTls')->willReturn(true);

        return new Crawler($curlFactory, $storeManager, $config, $this->createStub(LoggerInterface::class));
    }

    public function testCrawlWorksWithoutAProgressCallback(): void
    {
        $this->assertCount(4, $this->crawler()->crawl(1, 4));
    }

    public function testProgressCallbackSeesEveryPageAndTheRemainingQueue(): void
    {
        $seen = [];

        $results = $this->crawler()->crawl(1, 3, function (int $crawled, int $queued) use (&$seen): bool {
            $seen[] = [$crawled, $queued];
            return true;
        });

        $this->assertCount(3, $results);
        $this->assertSame([[1, 0], [2, 0], [3, 0]], $seen);
    }

    public function testReturningFalseFromTheCallbackStopsTheCrawl(): void
    {
        $results = $this->crawler()->crawl(1, 50, static fn (int $crawled): bool => $crawled < 5);

        $this->assertCount(5, $results);
    }

    public function testANullReturnFromTheCallbackDoesNotStopTheCrawl(): void
    {
        $results = $this->crawler()->crawl(1, 6, static function (): void {
        });

        $this->assertCount(6, $results);
    }
}
