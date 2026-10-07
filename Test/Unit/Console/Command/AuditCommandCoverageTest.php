<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Console\Command\AuditCommand;
use Panth\AdvancedSEO\Model\Audit\CrawlRunner;
use Panth\AdvancedSEO\Model\Audit\CrawlState;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class AuditCommandCoverageTest extends TestCase
{
    private array $wheres = [];

    private array $runs = [];

    private function resource(array $fetchOne): ResourceConnection
    {
        $this->wheres = [];
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->wheres[] = [$cond, $value];
            return $select;
        });
        $calls = 0;
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturnCallback(static function () use (&$calls, $fetchOne) {
            return $fetchOne[$calls++] ?? '0';
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return $resource;
    }

    private function legacyTester(array $fetchOne): CommandTester
    {
        return new CommandTester(new AuditCommand($this->resource($fetchOne), $this->createStub(AppState::class)));
    }

    private function auditTester(int $pages = 3, bool $active = false, ?int $defaultStore = 1): CommandTester
    {
        $this->runs = [];
        $catalog = new IssueCatalog();
        $issues = [
            $catalog->create('empty_anchor_text', 'https://example.com/' . str_repeat('a', 80), '/a', 'No text'),
            $catalog->create('structured_data_invalid', 'https://example.com/p', 'Product.datePublished', 'Bad'),
        ];

        $runner = $this->createStub(CrawlRunner::class);
        $runner->method('run')->willReturnCallback(
            function (int $storeId, ?int $maxPages = null, bool $persist = true) use ($pages, $issues): array {
                $this->runs[] = [$storeId, $maxPages, $persist];
                return [
                    'results' => [], 'summary' => [], 'pages' => $pages, 'max_pages' => 10,
                    'saved' => 0, 'cancelled' => false, 'audit_issues' => $issues,
                ];
            }
        );
        $state = $this->createStub(CrawlState::class);
        $state->method('isActive')->willReturn($active);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($defaultStore !== null) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($defaultStore);
            $storeManager->method('getDefaultStoreView')->willReturn($store);
        } else {
            $storeManager->method('getDefaultStoreView')->willReturn(null);
        }

        return new CommandTester(new AuditCommand(
            $this->resource([]),
            $this->createStub(AppState::class),
            $runner,
            $state,
            $storeManager
        ));
    }

    public function testLegacyReportIsPrintedAsJson(): void
    {
        $tester = $this->legacyTester(['2', '3', '1', '71.456', '40']);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--store' => '2']));
        $report = json_decode($tester->getDisplay(), true);
        $this->assertSame([
            'store_id'            => 2,
            'total_resolved'      => 40,
            'missing_title'       => 2,
            'missing_description' => 3,
            'duplicate_groups'    => 1,
            'average_score'       => 71.46,
        ], $report);
        $this->assertContains(['store_id = ?', 2], $this->wheres);
    }

    public function testAllStoresReportHasNoStoreFilter(): void
    {
        $tester = $this->legacyTester([]);
        $tester->execute([]);

        $this->assertNotContains(['store_id = ?', 0], $this->wheres);
        $this->assertContains(['1=1', null], $this->wheres);
    }

    public function testLegacyThresholdsDriveTheExitCode(): void
    {
        $this->assertSame(Command::FAILURE, $this->legacyTester(['2', '3'])->execute(['--fail-on-missing' => '5']));
        $this->assertSame(Command::SUCCESS, $this->legacyTester(['2', '2'])->execute(['--fail-on-missing' => '5']));
        $this->assertSame(Command::FAILURE, $this->legacyTester(['0', '0', '4'])->execute(['--fail-on-duplicate' => '4']));
        $this->assertSame(Command::SUCCESS, $this->legacyTester(['0', '0', '3'])->execute(['--fail-on-duplicate' => '4']));
        $this->assertSame(Command::FAILURE, $this->legacyTester(['0', '0', '0', '49.9'])->execute(['--fail-on-score' => '50']));
        $this->assertSame(Command::SUCCESS, $this->legacyTester(['0', '0', '0', '50'])->execute(['--fail-on-score' => '50']));
    }

    public function testInvalidSeverityIsRejected(): void
    {
        $tester = $this->auditTester();

        $this->assertSame(AuditCommand::EXIT_NOT_RUN, $tester->execute(['--severity' => 'fatal']));
        $this->assertStringContainsString('--severity must be one of', $tester->getDisplay());
    }

    public function testMissingServicesStopTheSiteAudit(): void
    {
        $tester = $this->legacyTester([]);

        $this->assertSame(AuditCommand::EXIT_NOT_RUN, $tester->execute(['--format' => 'json']));
        $this->assertStringContainsString('The Site Audit services are not available.', $tester->getDisplay());
    }

    public function testDefaultStoreViewIsAuditedWithTableOutput(): void
    {
        $tester = $this->auditTester(3, false, 4);

        $this->assertSame(1, $tester->execute(['--max-pages' => '0', '--no-save' => true]));
        $this->assertSame([[4, 1, false]], $this->runs);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Store 4: 3 page(s) crawled. Errors: 1, warnings: 0, notices: 1.', $display);
        $this->assertStringContainsString('...', $display);
        $this->assertStringContainsString('structured_data_invalid', $display);
    }

    public function testSeverityFilterIsSummarisedInTableOutput(): void
    {
        $tester = $this->auditTester();
        $tester->execute(['--store' => '1', '--severity' => 'ERROR']);

        $this->assertStringContainsString('Listing error and worse (1).', $tester->getDisplay());
        $this->assertStringNotContainsString('empty_anchor_text', $tester->getDisplay());
    }

    public function testNoStoreActiveCrawlOrUncrawlableStoreIsNotRun(): void
    {
        $tester = $this->auditTester(3, false, null);
        $this->assertSame(AuditCommand::EXIT_NOT_RUN, $tester->execute(['--format' => 'table']));
        $this->assertStringContainsString('No store view to audit.', $tester->getDisplay());

        $tester = $this->auditTester(3, true);
        $this->assertSame(AuditCommand::EXIT_NOT_RUN, $tester->execute(['--store' => '1', '--format' => 'table']));
        $this->assertStringContainsString('A crawl of store 1 is already queued or running.', $tester->getDisplay());

        $tester = $this->auditTester(0);
        $this->assertSame(AuditCommand::EXIT_NOT_RUN, $tester->execute(['--store' => '1', '--format' => 'table']));
        $this->assertStringContainsString('Store 1 could not be crawled.', $tester->getDisplay());
    }

    public function testCsvEscapesValues(): void
    {
        $catalog = new IssueCatalog();
        $issue = $catalog->create('empty_anchor_text', 'https://example.com/', '/a', 'Says "hi", twice');
        $command = new AuditCommand($this->resource([]), $this->createStub(AppState::class));

        $csv = $command->toCsv([$issue]);

        $this->assertStringContainsString('"Says ""hi"", twice"', $csv);
        $this->assertSame(2, substr_count(trim($csv), "\n") + 1);
    }
}
