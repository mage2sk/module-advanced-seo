<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Cron;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Cron\CrawlAudit;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\CrawlRunner;
use Panth\AdvancedSEO\Model\Audit\CrawlState;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CrawlAuditTest extends TestCase
{
    private array $ran = [];

    private array $logged = [];

    private function cron(array $storeIds, array $enabled, array $active = [], array $failing = []): CrawlAudit
    {
        $this->ran = [];
        $this->logged = [];

        $stores = [];
        foreach ($storeIds as $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $stores[] = $store;
        }
        $repository = $this->createStub(StoreRepositoryInterface::class);
        $repository->method('getList')->willReturn($stores);

        $runner = $this->createStub(CrawlRunner::class);
        $runner->method('run')->willReturnCallback(
            function (int $storeId, ?int $maxPages = null) use ($failing): array {
                if (in_array($storeId, $failing, true)) {
                    throw new \RuntimeException('boom ' . $storeId);
                }
                $this->ran[$storeId] = $maxPages;
                return [];
            }
        );

        $state = $this->createStub(CrawlState::class);
        $state->method('isActive')->willReturnCallback(static fn(int $id): bool => in_array($id, $active, true));

        $config = $this->createStub(Config::class);
        $config->method('isCrawlAuditEnabled')->willReturnCallback(
            static fn(?int $id = null): bool => in_array($id, $enabled, true)
        );
        $config->method('getCrawlDepth')->willReturnCallback(static fn(?int $id = null): int => 10 * (int) $id);

        $logger = $this->createStub(LoggerInterface::class);
        foreach (['info', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message) use ($level): void {
                    $this->logged[] = [$level, $message];
                }
            );
        }

        return new CrawlAudit($repository, $runner, $state, $config, $logger);
    }

    public function testEnabledStoresAreCrawledWithTheirConfiguredDepth(): void
    {
        $this->cron([0, 1, 2, 3], [0, 1, 3])->execute();

        $this->assertSame([1 => 10, 3 => 30], $this->ran);
        $this->assertSame([], $this->logged);
    }

    public function testAStoreWithAnActiveCrawlIsSkippedAndLogged(): void
    {
        $this->cron([1, 2], [1, 2], [1])->execute();

        $this->assertSame([2 => 20], $this->ran);
        $this->assertSame('info', $this->logged[0][0]);
        $this->assertStringContainsString('store 1 already has a crawl', $this->logged[0][1]);
    }

    public function testAFailingStoreIsLoggedAndTheNextOneStillRuns(): void
    {
        $this->cron([1, 2], [1, 2], [], [1])->execute();

        $this->assertSame([2 => 20], $this->ran);
        $this->assertSame(['error', 'Panth SEO CrawlAudit: store 1 failed: boom 1'], $this->logged[0]);
    }
}
