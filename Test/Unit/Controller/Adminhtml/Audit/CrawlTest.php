<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Controller\Adminhtml\Audit;

use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Controller\Adminhtml\Audit\Crawl;
use Panth\AdvancedSEO\Controller\Adminhtml\Audit\ExportIssues;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\CrawlRunner;
use Panth\AdvancedSEO\Model\Audit\CrawlState;
use Panth\AdvancedSEO\Model\Audit\CronActivity;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\IssuePersister;
use Panth\AdvancedSEO\Test\Unit\Controller\Adminhtml\ControllerHarness;
use PHPUnit\Framework\TestCase;

class CrawlTest extends TestCase
{
    use ControllerHarness;

    private array $queued = [];

    private array $runs = [];

    private function crawl(array $params, array $options = []): Crawl
    {
        $this->queued = [];
        $this->runs = [];

        $runner = $this->createStub(CrawlRunner::class);
        $runner->method('run')->willReturnCallback(function (int $storeId, ?int $limit = null) use ($options): array {
            $this->runs[] = [$storeId, $limit];
            if ($options['run_fails'] ?? false) {
                throw new \RuntimeException('curl failed');
            }
            return ['pages' => $options['pages'] ?? 5, 'issues' => 2];
        });
        $state = $this->createStub(CrawlState::class);
        $state->method('get')->willReturn(($options['state'] ?? []) + [
            'status' => CrawlState::STATUS_IDLE, 'stale' => false, 'crawled' => 4, 'max_pages' => 40,
        ]);
        $state->method('queue')->willReturnCallback(function (int $storeId, int $max, string $by = ''): array {
            $this->queued[] = [$storeId, $max, $by];
            return [];
        });
        $cron = $this->createStub(CronActivity::class);
        $cron->method('isActive')->willReturn($options['cron'] ?? false);

        $current = $this->createStub(Store::class);
        $current->method('getId')->willReturn($options['current_store'] ?? 0);
        $current->method('getBaseUrl')->willReturn('https://shop.test/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($current);

        $listed = [];
        foreach ($options['stores'] ?? [0, 1] as $id) {
            $s = $this->createStub(StoreInterface::class);
            $s->method('getId')->willReturn($id);
            $listed[] = $s;
        }
        $named = $this->createStub(StoreInterface::class);
        $named->method('getName')->willReturn('Default');
        $repository = $this->createStub(StoreRepositoryInterface::class);
        $repository->method('getList')->willReturn($listed);
        $repository->method('getById')->willReturn($named);

        $config = $this->createStub(Config::class);
        $config->method('getCrawlDepth')->willReturn($options['depth'] ?? 100);

        return new Crawl($this->controllerContext($params), $runner, $state, $cron, $storeManager, $repository, $config);
    }

    public function testWithCronTheCrawlIsQueuedForTheAdmin(): void
    {
        $this->adminUser = 'jane';
        $this->crawl(['store' => 2], ['cron' => true])->execute();
        $this->adminUser = null;

        $this->assertSame([[2, 100, 'jane']], $this->queued);
        $this->assertSame('success', $this->messages[0][0]);
        $this->assertStringContainsString('queued (up to 100 pages)', $this->messages[0][1]);
        $this->assertSame(['*/audit/index', ['store' => 2]], $this->redirect);
    }

    public function testQueuedOrRunningCrawlsAreNotRestarted(): void
    {
        $this->crawl(['store' => 2], ['state' => ['status' => CrawlState::STATUS_PENDING]])->execute();
        $this->assertSame([['notice', 'A crawl of store view "Default" is already queued.']], $this->messages);

        $this->crawl(['store' => 2], ['state' => ['status' => CrawlState::STATUS_RUNNING]])->execute();
        $this->assertSame([['notice', 'A crawl of store view "Default" is already running (4 of 40 pages).']], $this->messages);

        $this->crawl(['store' => 2], ['state' => ['status' => CrawlState::STATUS_RUNNING, 'stale' => true], 'cron' => true])->execute();
        $this->assertCount(1, $this->queued);
        $this->assertSame('', $this->queued[0][2]);
        $this->assertSame([], $this->runs);
    }

    public function testWithoutCronASmallCrawlRunsInline(): void
    {
        $this->crawl(['store' => 2], ['depth' => 100])->execute();
        $this->assertSame([[2, CrawlRunner::SYNC_PAGE_LIMIT]], $this->runs);
        $this->assertSame('warning', $this->messages[0][0]);
        $this->assertStringContainsString('capped at 5 of 100 pages (2 issues found)', $this->messages[0][1]);

        $this->crawl(['store' => 2], ['depth' => 10])->execute();
        $this->assertSame([[2, 10]], $this->runs);
        $this->assertSame([['success', 'Crawl of store view "Default" complete: 5 pages crawled, 2 issues found.']], $this->messages);

        $this->crawl(['store' => 2], ['depth' => 10, 'pages' => 0])->execute();
        $this->assertStringContainsString('could not reach any page. Check that https://shop.test/ is reachable', $this->messages[0][1]);
    }

    public function testStoreResolution(): void
    {
        $this->crawl([], ['current_store' => 3, 'depth' => 1])->execute();
        $this->assertSame(3, $this->runs[0][0]);
        $this->assertSame(['*/audit/index', ['store' => 3]], $this->redirect);

        $this->crawl([], ['stores' => [0, 4], 'depth' => 1])->execute();
        $this->assertSame(4, $this->runs[0][0]);

        $this->crawl([], ['stores' => [0]])->execute();
        $this->assertSame([['error', 'Crawl audit failed: No store view is available to crawl.']], $this->messages);
        $this->assertSame(['*/audit/index', ['store' => 0]], $this->redirect);
    }

    public function testRunnerFailuresAreReported(): void
    {
        $this->crawl(['store' => 1], ['run_fails' => true])->execute();

        $this->assertSame([['error', 'Crawl audit failed: curl failed']], $this->messages);
    }

    private function exportIssues(array $params, array $runIds = [5], int $previous = 4): array
    {
        $persister = $this->createStub(IssuePersister::class);
        $persister->method('getRunIds')->willReturn($runIds);
        $persister->method('getPreviousRunId')->willReturn($previous);
        $requested = [];
        $persister->method('getRunIssues')->willReturnCallback(static function (int $run, ?string $sev = null, ?string $cat = null) use (&$requested): array {
            $requested[] = [$run, $sev, $cat];
            $row = static fn(string $url): array => [
                'run_id' => $run, 'severity' => 'error', 'category' => 'content', 'code' => 'empty_anchor_text',
                'url' => $url, 'element' => 'a', 'detail' => 'Empty, "quoted"',
            ];
            return $run === 4 ? [$row('https://a/1')] : [$row('https://a/1'), $row('https://a/2')];
        });

        $headers = [];
        $contents = '';
        $raw = $this->createStub(Raw::class);
        $raw->method('setHeader')->willReturnCallback(static function ($name, $value) use (&$headers, $raw) {
            $headers[$name] = $value;
            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(static function ($value) use (&$contents, $raw) {
            $contents = $value;
            return $raw;
        });
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        (new ExportIssues($this->controllerContext($params), $persister, new IssueCatalog(), $rawFactory))->execute();

        $lines = array_map(
            static fn(string $line): array => str_getcsv($line, ',', '"', '\\'),
            explode("\n", trim($contents))
        );

        return [$headers, $lines, $requested];
    }

    public function testExportComparesAgainstThePreviousRun(): void
    {
        [$headers, $lines, $requested] = $this->exportIssues(['store' => 2, 'severity' => 'error', 'category' => 'content']);

        $this->assertSame('text/csv; charset=utf-8', $headers['Content-Type']);
        $this->assertSame('attachment; filename="panth-seo-audit-issues-store2-run5.csv"', $headers['Content-Disposition']);
        $this->assertSame(['run_id', 'severity', 'category', 'code', 'issue', 'url', 'element', 'detail', 'compare', 'fix_hint'], $lines[0]);
        $this->assertSame('unchanged', $lines[1][8]);
        $this->assertSame('new', $lines[2][8]);
        $this->assertSame('Empty, "quoted"', $lines[1][7]);
        $this->assertNotSame('', $lines[1][4]);
        $this->assertSame([[5, 'error', 'content'], [4, null, null]], $requested);
    }

    public function testExportOfAnExplicitFirstRunMarksEverythingNew(): void
    {
        [$headers, $lines, $requested] = $this->exportIssues(['run' => 9, 'severity' => 'bogus', 'category' => 'bogus'], [5], 0);

        $this->assertStringContainsString('store0-run9', $headers['Content-Disposition']);
        $this->assertSame('new', $lines[1][8]);
        $this->assertSame([[9, null, null]], $requested);
    }

    public function testExportWithoutAnyRun(): void
    {
        [$headers, , $requested] = $this->exportIssues([], [], 7);

        $this->assertStringContainsString('run0', $headers['Content-Disposition']);
        $this->assertSame([0, null, null], $requested[0]);
        $this->assertCount(1, $requested);
    }
}
