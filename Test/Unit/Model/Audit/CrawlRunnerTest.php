<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\CrawlResult;
use Panth\AdvancedSEO\Model\Audit\CrawlRunner;
use Panth\AdvancedSEO\Model\Audit\CrawlState;
use Panth\AdvancedSEO\Model\Audit\Crawler;
use Panth\AdvancedSEO\Model\Audit\IssueDetector;
use Panth\AdvancedSEO\Model\Audit\ResultPersister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CrawlRunnerTest extends TestCase
{
    private function crawlResult(string $url, array $issues = []): CrawlResult
    {
        return new CrawlResult($url, 200, 'Title ' . $url, 'Description for ' . $url, $url, '', $issues);
    }

    private function crawler(int $pages, ?array &$progress = null): Crawler
    {
        $crawler = $this->createStub(Crawler::class);
        $crawler->method('getRedirectMap')->willReturn([]);
        $crawler->method('crawl')->willReturnCallback(
            function (int $storeId, int $maxPages, ?callable $onProgress = null) use ($pages, &$progress): array {
                $results = [];

                for ($i = 1; $i <= min($pages, $maxPages); $i++) {
                    $results[] = $this->crawlResult('https://example.com/p' . $i);

                    if ($onProgress === null) {
                        continue;
                    }

                    $progress[] = $i;

                    if ($onProgress($i, $pages - $i) === false) {
                        break;
                    }
                }

                return $results;
            }
        );

        return $crawler;
    }

    private function state(array &$calls): CrawlState
    {
        $state = $this->createStub(CrawlState::class);
        $state->method('markRunning')->willReturnCallback(
            function (int $storeId, int $maxPages) use (&$calls): array {
                $calls[] = ['running', $storeId, $maxPages];
                return [];
            }
        );
        $state->method('reportProgress')->willReturnCallback(
            function (int $storeId, int $crawled, int $queued) use (&$calls): array {
                $calls[] = ['progress', $crawled, $queued];
                return ['cancel_requested' => false];
            }
        );
        $state->method('markComplete')->willReturnCallback(
            function (int $storeId, int $pages, int $issues, int $saved) use (&$calls): array {
                $calls[] = ['complete', $pages, $issues, $saved];
                return [];
            }
        );
        $state->method('markCancelled')->willReturnCallback(
            function (int $storeId, int $pages, int $saved) use (&$calls): array {
                $calls[] = ['cancelled', $pages, $saved];
                return [];
            }
        );
        $state->method('markFailed')->willReturnCallback(
            function (int $storeId, string $message) use (&$calls): array {
                $calls[] = ['failed', $message];
                return [];
            }
        );

        return $state;
    }

    private function runner(Crawler $crawler, CrawlState $state, ?ResultPersister $persister = null, int $configuredDepth = 100): CrawlRunner
    {
        $config = $this->createStub(Config::class);
        $config->method('getCrawlDepth')->willReturn($configuredDepth);

        return new CrawlRunner(
            $crawler,
            new IssueDetector(),
            $persister ?? $this->createStub(ResultPersister::class),
            $state,
            $config,
            $this->createStub(LoggerInterface::class)
        );
    }

    public function testRunPersistsResultsAndReportsCompletion(): void
    {
        $calls = [];
        $persister = $this->createStub(ResultPersister::class);
        $persister->method('persist')->willReturn(4);

        $outcome = $this->runner($this->crawler(4), $this->state($calls), $persister)->run(1, 10);

        $this->assertSame(4, $outcome['pages']);
        $this->assertSame(4, $outcome['saved']);
        $this->assertFalse($outcome['cancelled']);
        $this->assertSame(['running', 1, 10], $calls[0]);
        $this->assertContains(['complete', 4, $outcome['issues'], 4], $calls);
    }

    public function testDryRunDoesNotPersist(): void
    {
        $calls = [];
        $persister = $this->createMock(ResultPersister::class);
        $persister->expects($this->never())->method('persist');

        $outcome = $this->runner($this->crawler(3), $this->state($calls), $persister)->run(1, 10, false);

        $this->assertSame(0, $outcome['saved']);
        $this->assertSame(3, $outcome['pages']);
    }

    public function testMissingLimitFallsBackToConfiguredCrawlDepth(): void
    {
        $calls = [];
        $this->runner($this->crawler(2), $this->state($calls), null, 37)->run(1);

        $this->assertSame(['running', 1, 37], $calls[0]);
    }

    public function testProgressIsReportedEveryThirdPageOnly(): void
    {
        $calls = [];
        $this->runner($this->crawler(7), $this->state($calls))->run(1, 10);

        $reported = array_values(array_map(
            static fn (array $call): int => $call[1],
            array_filter($calls, static fn (array $call): bool => $call[0] === 'progress')
        ));

        $this->assertSame([3, 6], $reported);
    }

    public function testCancelRequestStopsTheCrawlAndKeepsWhatWasCrawled(): void
    {
        $calls = [];

        $state = $this->createStub(CrawlState::class);
        $state->method('markRunning')->willReturn([]);
        $state->method('reportProgress')->willReturn(['cancel_requested' => true]);
        $state->method('markCancelled')->willReturnCallback(
            function (int $storeId, int $pages, int $saved) use (&$calls): array {
                $calls[] = ['cancelled', $pages, $saved];
                return [];
            }
        );
        $state->method('markComplete')->willReturnCallback(
            function () use (&$calls): array {
                $calls[] = ['complete'];
                return [];
            }
        );

        $persister = $this->createStub(ResultPersister::class);
        $persister->method('persist')->willReturn(3);

        $outcome = $this->runner($this->crawler(20), $state, $persister)->run(1, 20);

        $this->assertTrue($outcome['cancelled']);
        $this->assertSame(3, $outcome['pages']);
        $this->assertSame([['cancelled', 3, 3]], $calls);
    }

    public function testFailureIsRecordedAndRethrown(): void
    {
        $calls = [];

        $crawler = $this->createStub(Crawler::class);
        $crawler->method('crawl')->willThrowException(new \RuntimeException('curl exploded'));

        $runner = $this->runner($crawler, $this->state($calls));

        $this->expectException(\RuntimeException::class);

        try {
            $runner->run(1, 10);
        } finally {
            $this->assertContains(['failed', 'curl exploded'], $calls);
        }
    }
}
