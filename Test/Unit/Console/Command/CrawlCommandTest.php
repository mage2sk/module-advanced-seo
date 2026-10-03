<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Console\Command;

use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Console\Command\CrawlCommand;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\CrawlResult;
use Panth\AdvancedSEO\Model\Audit\CrawlRunner;
use Panth\AdvancedSEO\Model\Audit\CrawlState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class CrawlCommandTest extends TestCase
{
    private array $runs = [];

    private function store(int $id, string $code): StoreInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        return $store;
    }

    private function tester(array $storeIds = [0, 1, 2], array $active = [], array $failing = [], bool $cancelled = false): CommandTester
    {
        $this->runs = [];

        $runner = $this->createStub(CrawlRunner::class);
        $runner->method('run')->willReturnCallback(
            function (int $storeId, ?int $maxPages = null, bool $persist = true) use ($failing, $cancelled): array {
                if (in_array($storeId, $failing, true)) {
                    throw new \RuntimeException('timeout');
                }
                $this->runs[] = [$storeId, $maxPages, $persist];
                return [
                    'results'   => [
                        new CrawlResult('https://shop.test/', 200, 'Home'),
                        new CrawlResult('https://shop.test/gone', 404, '', '', '', '', ['Page returns 404']),
                    ],
                    'summary'   => ['broken_links' => 1, 'missing_title' => 0],
                    'pages'     => 2,
                    'saved'     => 2,
                    'cancelled' => $cancelled,
                ];
            }
        );

        $state = $this->createStub(CrawlState::class);
        $state->method('isActive')->willReturnCallback(static fn(int $id): bool => in_array($id, $active, true));

        $stores = [];
        foreach ($storeIds as $id) {
            $stores[$id] = $this->store($id, $id === 0 ? 'admin' : 'store' . $id);
        }
        $repository = $this->createStub(StoreRepositoryInterface::class);
        $repository->method('getList')->willReturn(array_values($stores));
        $repository->method('getById')->willReturnCallback(static function (int $id) use ($stores) {
            if (!isset($stores[$id])) {
                throw new NoSuchEntityException(__('nope'));
            }
            return $stores[$id];
        });
        $repository->method('get')->willReturnCallback(static function (string $code) use ($stores) {
            foreach ($stores as $store) {
                if ($store->getCode() === $code) {
                    return $store;
                }
            }
            throw new NoSuchEntityException(__('nope'));
        });

        $config = $this->createStub(Config::class);
        $config->method('getCrawlDepth')->willReturn(150);

        return new CommandTester(
            new CrawlCommand($runner, $state, $repository, $config, $this->createStub(AppState::class))
        );
    }

    public function testAllStorefrontStoresAreCrawledWithTheConfiguredDepth(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame([[1, 150, true], [2, 150, true]], $this->runs);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Crawling store "store1" (ID 1), limit 150 pages...', $display);
        $this->assertStringContainsString('[200] https://shop.test/', $display);
        $this->assertStringContainsString('[404] https://shop.test/gone', $display);
        $this->assertStringContainsString('! Page returns 404', $display);
        $this->assertStringContainsString('Summary: 2 pages crawled, 1 total issues', $display);
        $this->assertStringContainsString('Broken links: 1', $display);
        $this->assertStringNotContainsString('Missing title', $display);
        $this->assertStringNotContainsString('Title: Home', $display);
        $this->assertStringContainsString('Saved 2 row(s) to the Crawl Results grid.', $display);
    }

    public function testVerboseOutputShowsTitles(): void
    {
        $tester = $this->tester([1]);
        $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $this->assertStringContainsString('Title: Home', $tester->getDisplay());
    }

    public function testLimitAndDryRunAreHonoured(): void
    {
        $tester = $this->tester([1]);

        $tester->execute(['--store' => 'store1', '--limit' => '0', '--dry-run' => true]);

        $this->assertSame([[1, 1, false]], $this->runs);
        $this->assertStringContainsString('Dry run: results were not saved.', $tester->getDisplay());
    }

    public function testActiveCrawlsAreSkippedUnlessForced(): void
    {
        $tester = $this->tester([1, 2], [1]);
        $tester->execute([]);
        $this->assertSame([[2, 150, true]], $this->runs);
        $this->assertStringContainsString('Skipping store "store1"', $tester->getDisplay());

        $tester = $this->tester([1, 2], [1]);
        $tester->execute(['--force' => true]);
        $this->assertCount(2, $this->runs);
    }

    public function testACancelledCrawlIsReported(): void
    {
        $tester = $this->tester([1], [], [], true);
        $tester->execute(['--store' => '1']);

        $this->assertStringContainsString('Crawl stopped early', $tester->getDisplay());
    }

    public function testAFailingStoreSetsTheFailureCodeButOthersRun(): void
    {
        $tester = $this->tester([1, 2], [], [1]);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertSame([[2, 150, true]], $this->runs);
        $this->assertStringContainsString('Failed for store store1: timeout', $tester->getDisplay());
    }

    public function testInvalidStoreSelectionsFail(): void
    {
        $tester = $this->tester();
        $this->assertSame(Command::FAILURE, $tester->execute(['--store' => '99']));
        $this->assertStringContainsString('Store not found: 99', $tester->getDisplay());

        $tester = $this->tester();
        $this->assertSame(Command::FAILURE, $tester->execute(['--store' => '0']));
        $this->assertStringContainsString('The admin store view cannot be crawled.', $tester->getDisplay());

        $tester = $this->tester([0]);
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('No active stores found.', $tester->getDisplay());
        $this->assertSame([], $this->runs);
    }
}
