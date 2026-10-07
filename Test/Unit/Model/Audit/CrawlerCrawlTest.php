<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\AuditUserAgent;
use Panth\AdvancedSEO\Model\Audit\Crawler;
use Panth\AdvancedSEO\Model\Audit\CrawlResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CrawlerCrawlTest extends TestCase
{
    private const BASE = 'https://example.com';

    private const ENV = 'PANTH_SEO_CRAWL_INTERNAL_HOST';

    private array $responses = [];

    private array $requested = [];

    private array $options = [];

    private array $addedHeaders = [];

    private string $current = '';

    private string|false $previousEnv = false;

    protected function setUp(): void
    {
        $this->previousEnv = getenv(self::ENV);
        putenv(self::ENV);
    }

    protected function tearDown(): void
    {
        putenv($this->previousEnv === false ? self::ENV : self::ENV . '=' . $this->previousEnv);
    }

    private function crawler(
        ?LoggerInterface $logger = null,
        string $baseUrl = self::BASE . '/',
        bool $verifyTls = true,
        ?AuditUserAgent $agent = null
    ): Crawler {
        $curl = $this->createStub(Curl::class);
        $curl->method('get')->willReturnCallback(function (string $url): void {
            $this->requested[] = $url;
            $this->current     = $url;
            $response          = $this->responses[$url] ?? [404, '', []];
            if ($response instanceof \Throwable) {
                throw $response;
            }
        });
        $curl->method('getStatus')->willReturnCallback(fn () => $this->responses[$this->current][0] ?? 404);
        $curl->method('getBody')->willReturnCallback(fn () => $this->responses[$this->current][1] ?? '');
        $curl->method('getHeaders')->willReturnCallback(fn () => $this->responses[$this->current][2] ?? []);
        $curl->method('setOption')->willReturnCallback(function (int $option, mixed $value): void {
            $this->options[$option] = $value;
        });
        $curl->method('addHeader')->willReturnCallback(function (string $name, string $value): void {
            $this->addedHeaders[$name] = $value;
        });

        $curlFactory = $this->createStub(CurlFactory::class);
        $curlFactory->method('create')->willReturn($curl);

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn($baseUrl);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $config = $this->createStub(Config::class);
        $config->method('getCrawlExcludePaths')->willReturn('');
        $config->method('crawlFollowsFilteredUrls')->willReturn(false);
        $config->method('crawlVerifiesTls')->willReturn($verifyTls);

        return new Crawler(
            $curlFactory,
            $storeManager,
            $config,
            $logger ?? $this->createStub(LoggerInterface::class),
            $agent
        );
    }

    private static function page(string $head, array $links = []): string
    {
        $anchors = '';
        foreach ($links as $href) {
            $anchors .= '<a href="' . $href . '">link</a>';
        }

        return '<html><head>' . $head . '</head><body>' . $anchors . '</body></html>';
    }

    public function testStoreWithoutHostIsNotCrawled(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('Panth SEO Crawler: could not determine host for store 3');

        $this->assertSame([], $this->crawler($logger, 'not-a-url')->crawl(3));
        $this->assertSame([], $this->requested);
    }

    public function testUnreachableBaseUrlAbortsTheCrawl(): void
    {
        $this->responses[self::BASE . '/'] = new \RuntimeException('Connection refused');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('is unreachable from this environment'));

        $crawler = $this->crawler($logger);

        $this->assertSame([], $crawler->crawl(1));
        $this->assertFalse($crawler->wasLastCrawlComplete());
    }

    public function testMetadataIsExtractedAndRedirectsAreFollowed(): void
    {
        $this->responses = [
            self::BASE . '/'    => [200, self::page(
                '<title>Home &amp; Garden</title>'
                . '<meta content="Fresh plants" name="description">'
                . '<link href="https://example.com/" rel="canonical">',
                ['/old']
            ), []],
            self::BASE . '/old' => [301, '', ['Location' => '/new']],
            self::BASE . '/new' => [200, self::page('<title>New</title><link rel="canonical" href="https://example.com/new">'), []],
        ];
        $crawler = $this->crawler();

        $results = $crawler->crawl(1);

        $this->assertSame([self::BASE . '/', self::BASE . '/old', self::BASE . '/new'], array_map(
            static fn (CrawlResult $r): string => $r->url,
            $results
        ));
        $this->assertSame('Home & Garden', $results[0]->title);
        $this->assertSame('Fresh plants', $results[0]->description);
        $this->assertSame('https://example.com/', $results[0]->canonical);
        $this->assertSame(301, $results[1]->statusCode);
        $this->assertSame('https://example.com/new', $results[2]->canonical);
        $this->assertSame([self::BASE . '/old' => [self::BASE . '/new']], $crawler->getRedirectMap());
        $this->assertTrue($crawler->wasLastCrawlComplete());
    }

    public function testRedirectToAnotherHostIsRecordedButNotCrawled(): void
    {
        $this->responses = [
            self::BASE . '/'    => [200, self::page('', ['/out']), []],
            self::BASE . '/out' => [302, '', ['location' => ['https://other.com/x']]],
        ];
        $crawler = $this->crawler();

        $this->assertCount(2, $crawler->crawl(1));
        $this->assertSame([self::BASE . '/out' => ['https://other.com/x']], $crawler->getRedirectMap());
        $this->assertNotContains('https://other.com/x', $this->requested);
    }

    public function testNofollowPagesDoNotQueueTheirLinks(): void
    {
        $this->responses[self::BASE . '/'] = [200, self::page(
            '<meta name="robots" content="noindex, NOFOLLOW">',
            ['/hidden']
        ), []];

        $results = $this->crawler()->crawl(1);

        $this->assertCount(1, $results);
        $this->assertSame('noindex, NOFOLLOW', $results[0]->robots);
        $this->assertSame([self::BASE . '/'], $this->requested);
    }

    public function testConsecutiveServerErrorsStopTheCrawl(): void
    {
        $this->responses[self::BASE . '/'] = [200, self::page('', ['/a', '/b', '/c', '/d']), []];
        foreach (['/a', '/b', '/c', '/d'] as $path) {
            $this->responses[self::BASE . $path] = [500, 'error', []];
        }
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('3 consecutive 5xx responses for store 1, stopping after 4 page(s)'));
        $crawler = $this->crawler($logger);

        $this->assertCount(4, $crawler->crawl(1));
        $this->assertFalse($crawler->wasLastCrawlComplete());
        $this->assertNotContains(self::BASE . '/d', $this->requested);
    }

    public function testConsecutiveFetchFailuresStopTheCrawl(): void
    {
        $paths = ['/f1', '/f2', '/f3', '/f4', '/f5', '/f6'];
        $this->responses[self::BASE . '/'] = [200, self::page('', $paths), []];
        foreach ($paths as $path) {
            $this->responses[self::BASE . $path] = new \RuntimeException('timeout');
        }
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('5 consecutive unreachable responses'));
        $crawler = $this->crawler($logger);

        $results = $crawler->crawl(1);

        $this->assertCount(6, $results);
        $this->assertSame(0, $results[5]->statusCode);
        $this->assertSame(['Fetch failed: timeout'], $results[5]->issues);
        $this->assertFalse($crawler->wasLastCrawlComplete());
    }

    public function testPageCallbackReceivesResponsesAndItsFailuresAreLogged(): void
    {
        $this->responses = [
            self::BASE . '/'  => [200, self::page('<title>Home</title>', ['/p']), ['content-type' => 'text/html']],
            self::BASE . '/p' => [200, self::page('<title>P</title>'), []],
        ];
        $seen   = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('Panth SEO Crawler: page callback failed for https://example.com/p: callback broke');

        $results = $this->crawler($logger)->crawl(1, 10, null, function (CrawlResult $r, string $body, array $headers, float $ms) use (&$seen): void {
            $seen[] = [$r->url, $headers, $ms >= 0.0, str_contains($body, '<title>')];
            if ($r->url === self::BASE . '/p') {
                throw new \RuntimeException('callback broke');
            }
        });

        $this->assertCount(2, $results);
        $this->assertSame([self::BASE . '/', ['content-type' => 'text/html'], true, true], $seen[0]);
        $this->assertSame(self::BASE . '/p', $seen[1][0]);
    }

    public function testPageLimitLeavesTheCrawlIncomplete(): void
    {
        $this->responses[self::BASE . '/'] = [200, self::page('', ['/a', '/b']), []];
        $crawler = $this->crawler();

        $this->assertCount(1, $crawler->crawl(1, 1));
        $this->assertFalse($crawler->wasLastCrawlComplete());
    }

    public function testCurlIsConfiguredWithUserAgentAndTlsSetting(): void
    {
        $this->responses[self::BASE . '/'] = [200, self::page(''), []];

        $this->crawler(null, self::BASE . '/', false)->crawl(1);

        $this->assertSame('PanthSeoAudit (+https://example.com/)', $this->options[CURLOPT_USERAGENT]);
        $this->assertFalse($this->options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(0, $this->options[CURLOPT_SSL_VERIFYHOST]);
        $this->assertFalse($this->options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testInjectedUserAgentIsUsed(): void
    {
        $this->responses[self::BASE . '/'] = [200, self::page(''), []];

        $this->crawler(null, self::BASE . '/', true, new AuditUserAgent())->crawl(1);

        $this->assertSame('PanthSeoAudit/dev (+https://example.com/)', $this->options[CURLOPT_USERAGENT]);
        $this->assertSame(2, $this->options[CURLOPT_SSL_VERIFYHOST]);
    }

    public function testInternalHostOverrideRewritesTheTransportUrl(): void
    {
        putenv(self::ENV . '=http://internal-nginx:8080');
        $this->responses['http://internal-nginx:8080/'] = [200, self::page('<title>Home</title>'), []];

        $results = $this->crawler()->crawl(1);

        $this->assertSame(['http://internal-nginx:8080/'], $this->requested);
        $this->assertSame(
            str_starts_with(self::BASE, 'https:') ? ['Host' => 'example.com', 'X-Forwarded-Proto' => 'https'] : ['Host' => 'example.com'],
            $this->addedHeaders
        );
        $this->assertSame(self::BASE . '/', $results[0]->url);
        $this->assertSame('Home', $results[0]->title);
    }

    public function testPrivateUrlDetection(): void
    {
        $this->assertTrue(Crawler::isPrivateUrl('https://example.com/customer/account'));
        $this->assertTrue(Crawler::isPrivateUrl('https://example.com/admin_123/'));
        $this->assertTrue(Crawler::isPrivateUrl('https://example.com/shoes?___SID=U'));
        $this->assertFalse(Crawler::isPrivateUrl('https://example.com/'));
        $this->assertFalse(Crawler::isPrivateUrl('https://example.com/customers-say'));
    }
}
