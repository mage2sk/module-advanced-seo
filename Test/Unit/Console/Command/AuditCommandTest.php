<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Console\Command\AuditCommand;
use Panth\AdvancedSEO\Model\Audit\CrawlRunner;
use Panth\AdvancedSEO\Model\Audit\CrawlState;
use Panth\AdvancedSEO\Model\Audit\Issue;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AuditCommandTest extends TestCase
{
    private function issues(bool $withError): array
    {
        $catalog = new IssueCatalog();
        $issues  = [
            $catalog->create('empty_anchor_text', 'https://example.com/', '/a', 'No text'),
            $catalog->create('nofollow_internal_link', 'https://example.com/', '/b', 'Nofollow'),
        ];
        if ($withError) {
            $issues[] = $catalog->create('structured_data_invalid', 'https://example.com/p', 'Product.datePublished', 'Bad');
        }

        return $issues;
    }

    private function tester(array $issues): CommandTester
    {
        $runner = $this->createStub(CrawlRunner::class);
        $runner->method('run')->willReturn([
            'results'         => [],
            'summary'         => ['missing_title' => 0],
            'issues'          => 0,
            'saved'           => 0,
            'pages'           => 3,
            'max_pages'       => 3,
            'cancelled'       => false,
            'audit_issues'    => $issues,
            'severity_counts' => CrawlRunner::countBySeverity($issues),
            'run_id'          => 9,
            'audit_stats'     => [],
        ]);
        $state = $this->createStub(CrawlState::class);
        $state->method('isActive')->willReturn(false);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);

        $connection = $this->createStub(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $select     = $this->createStub(\Magento\Framework\DB\Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('0');
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new CommandTester(new AuditCommand(
            $resource,
            $this->createStub(AppState::class),
            $runner,
            $state,
            $storeManager
        ));
    }

    public function testExitCodeIsOneWhenAnErrorSeverityIssueExists(): void
    {
        $tester = $this->tester($this->issues(true));

        $this->assertSame(1, $tester->execute(['--store' => '1', '--max-pages' => '3', '--format' => 'json']));
        $report = json_decode($tester->getDisplay(), true);
        $this->assertSame(1, $report['severity_counts']['error']);
        $this->assertArrayHasKey('missing_title', $report);
        $this->assertCount(3, $report['issues']);
    }

    public function testExitCodeIsZeroWithoutErrors(): void
    {
        $this->assertSame(0, $this->tester($this->issues(false))->execute(['--store' => '1', '--format' => 'table']));
    }

    public function testSeverityFilterListsOnlyErrorsButExitCodeUsesAllIssues(): void
    {
        $tester = $this->tester($this->issues(true));

        $this->assertSame(1, $tester->execute(['--store' => '1', '--format' => 'csv', '--severity' => 'error']));
        $lines = array_values(array_filter(explode("\n", trim($tester->getDisplay()))));
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('severity,category,code,url,element,detail,fix_hint', $lines[0]);
        $this->assertStringStartsWith('error,structured_data,structured_data_invalid', $lines[1]);
    }

    public function testInvalidFormatIsRejected(): void
    {
        $this->assertSame(AuditCommand::EXIT_NOT_RUN, $this->tester([])->execute(['--format' => 'xml']));
    }

    public function testHelpers(): void
    {
        $issues = $this->issues(true);

        $this->assertSame(1, AuditCommand::exitCodeFor($issues));
        $this->assertSame(0, AuditCommand::exitCodeFor($this->issues(false)));
        $this->assertCount(1, AuditCommand::filterBySeverity($issues, Issue::SEVERITY_ERROR));
        $this->assertCount(2, AuditCommand::filterBySeverity($issues, Issue::SEVERITY_WARNING));
        $this->assertCount(3, AuditCommand::filterBySeverity($issues, null));
    }
}
