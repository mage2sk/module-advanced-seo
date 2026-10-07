<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AdvancedSEO\Model\Audit\CrawlState;
use PHPUnit\Framework\TestCase;

class CrawlStateTest extends TestCase
{
    private array $flags = [];

    private int $now = 1757000000;

    private function state(): CrawlState
    {
        $this->flags = [];

        $flagManager = $this->createStub(FlagManager::class);
        $flagManager->method('saveFlag')->willReturnCallback(
            function (string $code, $value): bool {
                $this->flags[$code] = $value;
                return true;
            }
        );
        $flagManager->method('getFlagData')->willReturnCallback(
            fn (string $code) => $this->flags[$code] ?? null
        );
        $flagManager->method('deleteFlag')->willReturnCallback(
            function (string $code): bool {
                unset($this->flags[$code]);
                return true;
            }
        );

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnCallback(fn () => $this->now);
        $dateTime->method('gmtDate')->willReturnCallback(
            function ($format = null, $input = null) {
                $format = $format ?: 'Y-m-d H:i:s';
                return gmdate($format, $input !== null ? (int) $input : $this->now);
            }
        );

        return new CrawlState($flagManager, $dateTime);
    }

    public function testUnknownStoreIsIdle(): void
    {
        $state = $this->state()->get(7);

        $this->assertSame(CrawlState::STATUS_IDLE, $state['status']);
        $this->assertFalse($state['stale']);
        $this->assertSame(0, $state['crawled']);
    }

    public function testQueueStoresPendingRequest(): void
    {
        $crawlState = $this->state();
        $state = $crawlState->queue(1, 80, 'admin');

        $this->assertSame(CrawlState::STATUS_PENDING, $state['status']);
        $this->assertSame(80, $state['max_pages']);
        $this->assertSame('admin', $state['requested_by']);
        $this->assertTrue($crawlState->isActive(1));
    }

    public function testQueueForcesAtLeastOnePage(): void
    {
        $this->assertSame(1, $this->state()->queue(1, 0)['max_pages']);
    }

    public function testProgressAndCompletionTransitions(): void
    {
        $crawlState = $this->state();
        $crawlState->queue(2, 50);
        $crawlState->markRunning(2, 50);

        $running = $crawlState->reportProgress(2, 12, 30);
        $this->assertSame(CrawlState::STATUS_RUNNING, $running['status']);
        $this->assertSame(12, $running['crawled']);
        $this->assertSame(30, $running['queued']);
        $this->assertTrue($crawlState->isActive(2));

        $done = $crawlState->markComplete(2, 48, 9, 48);
        $this->assertSame(CrawlState::STATUS_COMPLETE, $done['status']);
        $this->assertSame(48, $done['pages']);
        $this->assertSame(9, $done['issues']);
        $this->assertSame(48, $done['saved']);
        $this->assertSame(0, $done['queued']);
        $this->assertFalse($crawlState->isActive(2));
    }

    public function testRunningAQueuedRequestKeepsWhoAskedForIt(): void
    {
        $crawlState = $this->state();
        $crawlState->queue(12, 40, 'admin');
        $this->now += 30;

        $running = $crawlState->markRunning(12, 40);

        $this->assertSame('admin', $running['requested_by']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $this->now - 30), $running['requested_at']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $this->now), $running['started_at']);
    }

    public function testAnUnqueuedRunDoesNotInheritAnEarlierRequest(): void
    {
        $crawlState = $this->state();
        $crawlState->queue(13, 40, 'admin');
        $crawlState->markComplete(13, 40, 1, 40);
        $this->now += 300;

        $running = $crawlState->markRunning(13, 10);

        $this->assertSame('', $running['requested_by']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $this->now), $running['requested_at']);
    }

    public function testFailureKeepsMessage(): void
    {
        $crawlState = $this->state();
        $crawlState->markRunning(3, 10);

        $failed = $crawlState->markFailed(3, 'boom');

        $this->assertSame(CrawlState::STATUS_FAILED, $failed['status']);
        $this->assertSame('boom', $failed['message']);
        $this->assertFalse($crawlState->isActive(3));
    }

    public function testCancelOfPendingRequestIsImmediate(): void
    {
        $crawlState = $this->state();
        $crawlState->queue(4, 20);

        $this->assertTrue($crawlState->requestCancel(4));
        $this->assertSame(CrawlState::STATUS_CANCELLED, $crawlState->get(4)['status']);
    }

    public function testCancelOfRunningCrawlFlagsTheWorker(): void
    {
        $crawlState = $this->state();
        $crawlState->markRunning(5, 20);

        $this->assertTrue($crawlState->requestCancel(5));
        $this->assertSame(CrawlState::STATUS_RUNNING, $crawlState->get(5)['status']);
        $this->assertTrue($crawlState->isCancelRequested(5));
    }

    public function testCancelWithNothingRunningIsRejected(): void
    {
        $this->assertFalse($this->state()->requestCancel(6));
    }

    public function testRunningCrawlGoesStaleWithoutHeartbeat(): void
    {
        $crawlState = $this->state();
        $crawlState->markRunning(8, 100);

        $this->assertFalse($crawlState->get(8)['stale']);
        $this->assertTrue($crawlState->isActive(8));

        $this->now += CrawlState::STALE_AFTER_SECONDS + 1;

        $this->assertTrue($crawlState->get(8)['stale']);
        $this->assertFalse($crawlState->isActive(8));
    }

    public function testHeartbeatKeepsARunningCrawlFresh(): void
    {
        $crawlState = $this->state();
        $crawlState->markRunning(9, 100);

        $this->now += CrawlState::STALE_AFTER_SECONDS - 10;
        $crawlState->reportProgress(9, 5, 5);
        $this->now += 20;

        $this->assertFalse($crawlState->get(9)['stale']);
    }

    public function testStaleRunningCrawlCanBeCancelledOutright(): void
    {
        $crawlState = $this->state();
        $crawlState->markRunning(10, 100);
        $this->now += CrawlState::STALE_AFTER_SECONDS + 1;

        $this->assertTrue($crawlState->requestCancel(10));
        $this->assertSame(CrawlState::STATUS_CANCELLED, $crawlState->get(10)['status']);
    }

    public function testClearRemovesTheState(): void
    {
        $crawlState = $this->state();
        $crawlState->queue(11, 10);
        $crawlState->clear(11);

        $this->assertSame(CrawlState::STATUS_IDLE, $crawlState->get(11)['status']);
    }

    public function testStatesAreKeptPerStore(): void
    {
        $crawlState = $this->state();
        $crawlState->queue(1, 10);
        $crawlState->markRunning(2, 20);

        $this->assertSame(CrawlState::STATUS_PENDING, $crawlState->get(1)['status']);
        $this->assertSame(CrawlState::STATUS_RUNNING, $crawlState->get(2)['status']);
    }
}
