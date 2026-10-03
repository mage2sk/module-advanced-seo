<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Cron;

use Panth\AdvancedSEO\Cron\PruneOrphans;
use Panth\AdvancedSEO\Model\Maintenance\OrphanCleaner;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PruneOrphansTest extends TestCase
{
    private array $logged = [];

    private function logger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(
            function (string $message, array $context = []): void {
                $this->logged[] = ['info', $message, $context];
            }
        );
        $logger->method('error')->willReturnCallback(
            function (string $message, array $context = []): void {
                $this->logged[] = ['error', $message, $context];
            }
        );

        return $logger;
    }

    private function cron(array|\Throwable $outcome, bool &$swept = null): PruneOrphans
    {
        $this->logged = [];

        $cleaner = $this->createStub(OrphanCleaner::class);
        $cleaner->method('sweep')->willReturnCallback(
            static function () use ($outcome): array {
                if ($outcome instanceof \Throwable) {
                    throw $outcome;
                }
                return $outcome;
            }
        );

        return new PruneOrphans($cleaner, $this->logger());
    }

    public function testItSweepsAuthoredRowsNever(): void
    {
        $cleaner = $this->createStub(OrphanCleaner::class);
        $seen = [];
        $cleaner->method('sweep')->willReturnCallback(
            static function (bool $includeAuthored = false) use (&$seen): array {
                $seen[] = $includeAuthored;
                return [];
            }
        );

        (new PruneOrphans($cleaner, $this->logger()))->execute();

        $this->assertSame([false], $seen);
    }

    public function testItLogsWhatItRemoved(): void
    {
        $this->cron(['panth_seo_score' => 13, 'panth_seo_meta_embedding' => 11])->execute();

        $this->assertCount(1, $this->logged);
        $this->assertSame('info', $this->logged[0][0]);
        $this->assertSame(13, $this->logged[0][2]['panth_seo_score']);
    }

    public function testItStaysQuietWhenThereIsNothingToRemove(): void
    {
        $this->cron([])->execute();

        $this->assertSame([], $this->logged);
    }

    public function testAFailedSweepIsLoggedAndSwallowed(): void
    {
        $this->cron(new \RuntimeException('table is gone'))->execute();

        $this->assertSame('error', $this->logged[0][0]);
        $this->assertStringContainsString('table is gone', $this->logged[0][1]);
    }
}
