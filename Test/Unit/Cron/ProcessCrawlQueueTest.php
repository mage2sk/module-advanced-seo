<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Cron;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Cron\ProcessCrawlQueue;
use Panth\AdvancedSEO\Model\Audit\CrawlRunner;
use Panth\AdvancedSEO\Model\Audit\CrawlState;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProcessCrawlQueueTest extends TestCase
{
    private array $ran = [];

    private array $failed = [];

    private function stores(array $ids): StoreRepositoryInterface
    {
        $stores = [];
        foreach ($ids as $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $store->method('getCode')->willReturn('store' . $id);
            $stores[] = $store;
        }

        $repository = $this->createStub(StoreRepositoryInterface::class);
        $repository->method('getList')->willReturn($stores);

        return $repository;
    }

    private function cron(array $states, array $ids = [1]): ProcessCrawlQueue
    {
        $this->ran = [];
        $this->failed = [];

        $crawlState = $this->createStub(CrawlState::class);
        $crawlState->method('get')->willReturnCallback(
            static fn (int $storeId): array => $states[$storeId] ?? [
                'status' => CrawlState::STATUS_IDLE,
                'stale' => false,
                'max_pages' => 0,
            ]
        );
        $crawlState->method('markFailed')->willReturnCallback(
            function (int $storeId, string $message): array {
                $this->failed[$storeId] = $message;
                return [];
            }
        );

        $runner = $this->createStub(CrawlRunner::class);
        $runner->method('run')->willReturnCallback(
            function (int $storeId, ?int $maxPages = null, bool $persist = true): array {
                $this->ran[$storeId] = $maxPages;
                return ['results' => [], 'summary' => [], 'issues' => 0, 'saved' => 0, 'pages' => 0, 'max_pages' => (int) $maxPages, 'cancelled' => false];
            }
        );

        return new ProcessCrawlQueue(
            $this->stores($ids),
            $crawlState,
            $runner,
            $this->createStub(LoggerInterface::class)
        );
    }

    private static function state(string $status, bool $stale = false, int $maxPages = 100): array
    {
        return ['status' => $status, 'stale' => $stale, 'max_pages' => $maxPages];
    }

    public function testPendingRequestIsRunWithItsOwnPageLimit(): void
    {
        $this->cron([1 => self::state(CrawlState::STATUS_PENDING, false, 40)])->execute();

        $this->assertSame([1 => 40], $this->ran);
        $this->assertSame([], $this->failed);
    }

    public function testNothingQueuedRunsNothing(): void
    {
        $this->cron([1 => self::state(CrawlState::STATUS_IDLE)])->execute();

        $this->assertSame([], $this->ran);
    }

    public function testARunningCrawlIsLeftAlone(): void
    {
        $this->cron([1 => self::state(CrawlState::STATUS_RUNNING)])->execute();

        $this->assertSame([], $this->ran);
        $this->assertSame([], $this->failed);
    }

    public function testAStalledRunningCrawlIsMarkedFailedAndNotRestarted(): void
    {
        $this->cron([1 => self::state(CrawlState::STATUS_RUNNING, true)])->execute();

        $this->assertSame([], $this->ran);
        $this->assertArrayHasKey(1, $this->failed);
        $this->assertStringContainsString('15 minutes', $this->failed[1]);
    }

    public function testTheAdminStoreIsNeverCrawled(): void
    {
        $this->cron([0 => self::state(CrawlState::STATUS_PENDING)], [0])->execute();

        $this->assertSame([], $this->ran);
    }

    public function testEveryStoreWithAQueuedRequestIsProcessed(): void
    {
        $this->cron(
            [
                1 => self::state(CrawlState::STATUS_PENDING, false, 10),
                2 => self::state(CrawlState::STATUS_COMPLETE),
                3 => self::state(CrawlState::STATUS_PENDING, false, 30),
            ],
            [1, 2, 3]
        )->execute();

        $this->assertSame([1 => 10, 3 => 30], $this->ran);
    }

    public function testAFailingStoreDoesNotStopTheOthers(): void
    {
        $ran = [];

        $crawlState = $this->createStub(CrawlState::class);
        $crawlState->method('get')->willReturn(self::state(CrawlState::STATUS_PENDING, false, 5));

        $runner = $this->createStub(CrawlRunner::class);
        $runner->method('run')->willReturnCallback(
            static function (int $storeId) use (&$ran): array {
                $ran[] = $storeId;
                if ($storeId === 1) {
                    throw new \RuntimeException('host down');
                }
                return ['results' => [], 'summary' => [], 'issues' => 0, 'saved' => 0, 'pages' => 0, 'max_pages' => 5, 'cancelled' => false];
            }
        );

        (new ProcessCrawlQueue(
            $this->stores([1, 2]),
            $crawlState,
            $runner,
            $this->createStub(LoggerInterface::class)
        ))->execute();

        $this->assertSame([1, 2], $ran);
    }
}
