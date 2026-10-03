<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Block\Adminhtml;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Block\Adminhtml\Audit;
use Panth\AdvancedSEO\Model\Audit\CrawlRunner;
use Panth\AdvancedSEO\Model\Audit\CrawlState;
use Panth\AdvancedSEO\Model\Audit\CronActivity;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\IssuePersister;
use Panth\AdvancedSEO\Model\Maintenance\EntityTableMap;
use Panth\AdvancedSEO\Model\Score\GradeCalculator;
use Panth\AdvancedSEO\Test\Unit\Block\BackendTemplateObjectManager;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use PHPUnit\Framework\TestCase;

class AuditTest extends TestCase
{
    use BackendTemplateObjectManager;
    use ConnectionRecorder;

    private array $urls = [];

    private function block(array $params = [], array $db = [], ?IssuePersister $persister = null, array $storeIds = [0, 1, 2]): Audit
    {
        $this->urls = [];
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $params[$key] ?? $default);
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(function ($route = null, $routeParams = []) {
            $this->urls[] = [$route, $routeParams];
            return 'https://admin.test/' . $route;
        });
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($url);

        $formKey = $this->createStub(FormKey::class);
        $formKey->method('getFormKey')->willReturn('fk123');

        $stores = [];
        foreach ($storeIds as $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $store->method('getName')->willReturn('Store ' . $id);
            $store->method('getCode')->willReturn('s' . $id);
            $stores[] = $store;
        }
        $repository = $this->createStub(StoreRepositoryInterface::class);
        $repository->method('getList')->willReturn($stores);

        $crawlState = $this->createStub(CrawlState::class);
        $crawlState->method('get')->willReturnCallback(static fn(int $id): array => ['status' => 'idle', 'store' => $id]);
        $cron = $this->createStub(CronActivity::class);
        $cron->method('isActive')->willReturn(true);
        $map = $this->createStub(EntityTableMap::class);
        $map->method('existsCondition')->willReturn('EXISTS(...)');

        return new Audit(
            $context,
            $this->recordingResource([], $db['all'] ?? [], null, $db['row'] ?? [], $db['tables'] ?? true),
            $formKey,
            new GradeCalculator(),
            $repository,
            $crawlState,
            $cron,
            $map,
            [],
            $persister,
            new IssueCatalog()
        );
    }

    public function testStoreOptionsSkipAdminAndSelectionFallsBackToTheFirstStore(): void
    {
        $block = $this->block(['store' => 99]);

        $this->assertSame([['value' => 1, 'label' => 'Store 1 (s1)'], ['value' => 2, 'label' => 'Store 2 (s2)']], $block->getStoreOptions());
        $this->assertSame(1, $block->getSelectedStoreId());
        $this->assertSame(2, $this->block(['store' => '2'])->getSelectedStoreId());
        $this->assertSame(0, $this->block([], [], null, [0])->getSelectedStoreId());
    }

    public function testSimpleAccessors(): void
    {
        $block = $this->block(['store' => 2]);

        $this->assertSame('fk123', $block->getFormKey());
        $this->assertSame(['status' => 'idle', 'store' => 2], $block->getCrawlState());
        $this->assertTrue($block->isCronActive());
        $this->assertSame(CrawlRunner::SYNC_PAGE_LIMIT, $block->getSyncPageLimit());
        $this->assertSame('AI Search', $block->getCategoryLabel('ai_search'));
        $this->assertSame('Structured Data', $block->getCategoryLabel('structured_data'));
    }

    public function testIssueFiltersAreValidated(): void
    {
        $this->assertSame('error', $this->block()->getIssueSeverityFilter());
        $this->assertSame('warning', $this->block(['issue_severity' => 'warning'])->getIssueSeverityFilter());
        $this->assertSame('error', $this->block(['issue_severity' => 'fatal'])->getIssueSeverityFilter());
        $this->assertSame('', $this->block(['issue_category' => 'bogus'])->getIssueCategoryFilter());
        $this->assertSame('content', $this->block(['issue_category' => 'content'])->getIssueCategoryFilter());
    }

    public function testFilterAndExportUrlsCarryTheStore(): void
    {
        $block = $this->block(['store' => 2]);

        $block->getIssueFilterUrl('warning', 'content');
        $block->getIssueFilterUrl('notice');
        $block->getIssueExportUrl();

        $this->assertSame(
            ['panth_seo/audit/index', ['store' => 2, 'issue_severity' => 'warning', '_fragment' => 'panth-seo-issues', 'issue_category' => 'content']],
            $this->urls[0]
        );
        $this->assertArrayNotHasKey('issue_category', $this->urls[1][1]);
        $this->assertSame(['panth_seo/audit/exportIssues', ['store' => 2]], $this->urls[2]);
    }

    private static function issue(string $code, string $url, string $severity = 'error', string $category = 'content'): array
    {
        return ['code' => $code, 'url' => $url, 'element' => '', 'severity' => $severity, 'category' => $category];
    }

    public function testIssueReportComparesWithThePreviousRun(): void
    {
        $persister = $this->createStub(IssuePersister::class);
        $persister->method('getRunIds')->willReturn([8]);
        $persister->method('getPreviousRunId')->willReturn(7);
        $persister->method('getRunCreatedAt')->willReturn('2026-10-01 10:00:00');
        $persister->method('getRunIssues')->willReturnCallback(static fn(int $run): array => $run === 8
            ? [
                self::issue('empty_anchor_text', 'https://a/1'),
                self::issue('empty_anchor_text', 'https://a/2'),
                self::issue('empty_anchor_text', 'https://a/2'),
                self::issue('structured_data_invalid', 'https://a/3', 'error', 'structured_data'),
                self::issue('slow_page', 'https://a/4', 'warning', 'performance'),
            ]
            : [
                self::issue('empty_anchor_text', 'https://a/1'),
                self::issue('structured_data_invalid', 'https://a/9', 'error', 'structured_data'),
                self::issue('broken_link', 'https://a/5'),
                self::issue('slow_page', 'https://a/6', 'warning', 'performance'),
            ]);

        $report = $this->block(['store' => 1], [], $persister)->getIssueReport(2);

        $this->assertSame(8, $report['run_id']);
        $this->assertSame(7, $report['previous_run_id']);
        $this->assertSame('2026-10-01 10:00:00', $report['created_at']);
        $this->assertSame(['error' => 4, 'warning' => 1, 'notice' => 0], $report['counts']);
        $this->assertSame(['content' => 3, 'structured_data' => 1], $report['categories']);

        $groups = array_column($report['groups'], null, 'code');
        $anchor = $groups['empty_anchor_text'];
        $this->assertSame(3, $anchor['total']);
        $this->assertSame(1, $anchor['unchanged']);
        $this->assertSame(2, $anchor['new']);
        $this->assertSame(2, $anchor['pages']);
        $this->assertCount(2, $anchor['rows']);
        $this->assertSame('unchanged', $anchor['rows'][0]['compare']);
        $this->assertNotSame('', $anchor['title']);

        $this->assertSame(1, $groups['structured_data_invalid']['fixed']);
        $this->assertSame('fixed', $groups['structured_data_invalid']['fixed_rows'][0]['compare']);
        $this->assertSame(0, $groups['broken_link']['total']);
        $this->assertSame(1, $groups['broken_link']['fixed']);
        $this->assertArrayNotHasKey('slow_page', $groups);
        $this->assertSame('empty_anchor_text', $report['groups'][0]['code']);
    }

    public function testIssueReportCategoryFilterAndFirstRun(): void
    {
        $persister = $this->createStub(IssuePersister::class);
        $persister->method('getRunIds')->willReturn([3]);
        $persister->method('getPreviousRunId')->willReturn(0);
        $persister->method('getRunIssues')->willReturn([
            self::issue('empty_anchor_text', 'https://a/1'),
            self::issue('structured_data_invalid', 'https://a/3', 'error', 'structured_data'),
        ]);

        $report = $this->block(['issue_category' => 'structured_data'], [], $persister)->getIssueReport();

        $this->assertCount(1, $report['groups']);
        $this->assertSame(1, $report['groups'][0]['new']);
        $this->assertSame(['content' => 1, 'structured_data' => 1], $report['categories']);
        $this->assertSame('structured_data', $report['category']);
    }

    public function testIssueReportNeedsAPersisterAndARun(): void
    {
        $this->assertNull($this->block()->getIssueReport());

        $persister = $this->createStub(IssuePersister::class);
        $persister->method('getRunIds')->willReturn([]);
        $this->assertNull($this->block([], [], $persister)->getIssueReport());
    }

    public function testLowScoringEntitiesGetAGrade(): void
    {
        $block = $this->block(['store' => 1], ['all' => [[['entity_id' => 1, 'score' => '42'], ['entity_id' => 2]]]]);

        $rows = $block->getLowScoringEntities(10);

        $this->assertSame((new GradeCalculator())->forScore(42), $rows[0]['grade']);
        $this->assertSame((new GradeCalculator())->forScore(0), $rows[1]['grade']);
        $this->assertContains(['entity_score.score < ?', 60], $this->db['where']);
        $this->assertContains(['EXISTS(...)', null], $this->db['where']);
    }

    public function testDuplicatesAndMissingTables(): void
    {
        $this->assertSame([['hash' => 'x']], $this->block([], ['all' => [[['hash' => 'x']]]])->getDuplicates());

        $missing = $this->block([], ['tables' => false]);
        $this->assertSame([], $missing->getDuplicates());
        $this->assertSame([], $missing->getLowScoringEntities());
        $this->assertNull($missing->getLastCrawlSummary());
    }

    public function testLastCrawlSummaryCastsCounts(): void
    {
        $summary = $this->block([], ['row' => [[
            'total_pages' => '10', 'status_200' => '7', 'status_301' => '1', 'status_404' => '1',
            'status_5xx' => '1', 'failed' => '0', 'pages_with_issues' => '3', 'last_crawled_at' => '',
        ]]])->getLastCrawlSummary();

        $this->assertSame(10, $summary['total_pages']);
        $this->assertSame(3, $summary['pages_with_issues']);
        $this->assertNull($summary['last_crawled_at']);

        $this->assertNull($this->block([], ['row' => [['total_pages' => '0']]])->getLastCrawlSummary());
        $this->assertNull($this->block([], ['row' => [false]])->getLastCrawlSummary());
    }
}
