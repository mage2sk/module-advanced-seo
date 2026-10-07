<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AdvancedSEO\Model\Maintenance\OrphanCleaner;
use Panth\AdvancedSEO\Model\Score\GradeCalculator;
use Panth\AdvancedSEO\Setup\Patch\Data\AddDefaultGoogleShoppingFeed;
use Panth\AdvancedSEO\Setup\Patch\Data\InstallDefaultTemplates;
use Panth\AdvancedSEO\Setup\Patch\Data\NormaliseSeoScoreGrades;
use Panth\AdvancedSEO\Setup\Patch\Data\PruneOrphanSeoRows;
use Panth\AdvancedSEO\Setup\Patch\Schema\AddFeedProfileTables;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DataPatchesTest extends TestCase
{
    use ConnectionRecorder;

    private array $logs = [];

    private array $setupCalls = [];

    private function logger(): LoggerInterface
    {
        $this->logs = [];
        $logger = $this->createStub(LoggerInterface::class);
        foreach (['info', 'warning'] as $level) {
            $logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level): void {
                $this->logs[] = [$level, $message, $context];
            });
        }
        return $logger;
    }

    private function dataSetup(object $connection): ModuleDataSetupInterface
    {
        $this->setupCalls = [];
        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);
        foreach (['startSetup', 'endSetup'] as $method) {
            $setup->method($method)->willReturnCallback(function () use ($method, $setup) {
                $this->setupCalls[] = $method;
                return $setup;
            });
        }
        return $setup;
    }

    public function testPruneSweepsAndLogsRemovedRows(): void
    {
        $cleaner = $this->createStub(OrphanCleaner::class);
        $cleaner->method('sweep')->willReturn(['panth_seo_score' => 3]);
        $patch = new PruneOrphanSeoRows($this->dataSetup($this->createStub(Mysql::class)), $cleaner, $this->logger());

        $this->assertSame($patch, $patch->apply());
        $this->assertSame([['info', 'Panth SEO: pruned SEO rows for deleted entities on upgrade', ['panth_seo_score' => 3]]], $this->logs);
        $this->assertSame(['startSetup', 'endSetup'], $this->setupCalls);
        $this->assertSame([], PruneOrphanSeoRows::getDependencies());
        $this->assertSame([], $patch->getAliases());
    }

    public function testPruneIsQuietWhenCleanAndWarnsOnFailure(): void
    {
        $cleaner = $this->createStub(OrphanCleaner::class);
        $cleaner->method('sweep')->willReturn([]);
        (new PruneOrphanSeoRows($this->dataSetup($this->createStub(Mysql::class)), $cleaner, $this->logger()))->apply();
        $this->assertSame([], $this->logs);

        $failing = $this->createStub(OrphanCleaner::class);
        $failing->method('sweep')->willThrowException(new \RuntimeException('table missing'));
        (new PruneOrphanSeoRows($this->dataSetup($this->createStub(Mysql::class)), $failing, $this->logger()))->apply();
        $this->assertSame([['warning', 'Panth SEO: could not prune SEO rows for deleted entities: table missing', []]], $this->logs);
        $this->assertSame(['startSetup', 'endSetup'], $this->setupCalls);
    }

    private function gradeConnection(bool $exists, int|\Throwable $updated): array
    {
        $connection = $this->createStub(Mysql::class);
        $connection->method('isTableExists')->willReturn($exists);
        $connection->method('update')->willReturnCallback(static function () use ($updated) {
            if ($updated instanceof \Throwable) {
                throw $updated;
            }
            return $updated;
        });
        return [$connection];
    }

    public function testGradesAreRealignedToScoreBands(): void
    {
        [$connection] = $this->gradeConnection(true, 2);
        $result = new NormaliseSeoScoreGrades($this->dataSetup($connection), new GradeCalculator(), $this->logger());
        $this->assertSame($result, $result->apply());

        $this->assertSame([['info', 'Panth SEO: normalised 10 stored SEO score grade(s) to the A-F scale.', []]], $this->logs);
        $this->assertSame([], NormaliseSeoScoreGrades::getDependencies());
        $this->assertSame([], $result->getAliases());
    }

    public function testGradeBoundsPerGrade(): void
    {
        $updates = [];
        $connection = $this->createStub(Mysql::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('update')->willReturnCallback(static function ($table, $data, $where) use (&$updates) {
            $updates[$data['grade']] = $where;
            return 0;
        });

        (new NormaliseSeoScoreGrades($this->dataSetup($connection), new GradeCalculator(), $this->logger()))->apply();

        $this->assertSame(['score >= ?' => 90, 'score <= ?' => 100, 'grade <> ?' => 'A'], $updates['A']);
        $this->assertSame(['score >= ?' => 80, 'score <= ?' => 89, 'grade <> ?' => 'B'], $updates['B']);
        $this->assertSame(['score >= ?' => 60, 'score <= ?' => 69, 'grade <> ?' => 'D'], $updates['D']);
        $this->assertSame(['score >= ?' => 0, 'score <= ?' => 59, 'grade <> ?' => 'F'], $updates['F']);
        $this->assertSame([], $this->logs);
    }

    public function testGradeNormalisationSkipsMissingTablesAndSurvivesErrors(): void
    {
        [$connection] = $this->gradeConnection(false, 1);
        (new NormaliseSeoScoreGrades($this->dataSetup($connection), new GradeCalculator(), $this->logger()))->apply();
        $this->assertSame([], $this->logs);

        [$connection] = $this->gradeConnection(true, new \RuntimeException('lock'));
        (new NormaliseSeoScoreGrades($this->dataSetup($connection), new GradeCalculator(), $this->logger()))->apply();
        $this->assertSame([['warning', 'Panth SEO: could not normalise stored score grades: lock', []]], $this->logs);
    }

    private function dateTime(): DateTime
    {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-10-03 00:00:00');
        return $dateTime;
    }

    public function testDefaultFeedIsSeededOnce(): void
    {
        $this->dbLastInsertId = '4';
        $patch = new AddDefaultGoogleShoppingFeed($this->recordingResource(), $this->dateTime());
        $patch->apply();

        $this->assertSame('panth_seo_feed_profile', $this->db['insert'][0][0]);
        $this->assertSame('Google Shopping Feed', $this->db['insert'][0][1]['name']);
        $this->assertSame('2026-10-03 00:00:00', $this->db['insert'][0][1]['created_at']);
        $this->assertCount(17, $this->db['insert']);
        $this->assertSame(
            ['feed_id' => 4, 'feed_field' => 'g:id', 'source_type' => 'attribute', 'source_value' => 'sku', 'default_value' => null, 'sort_order' => 10, 'is_required' => 1],
            $this->db['insert'][1][1]
        );
        $this->assertSame([AddFeedProfileTables::class], AddDefaultGoogleShoppingFeed::getDependencies());
        $this->assertSame([], $patch->getAliases());

        (new AddDefaultGoogleShoppingFeed($this->recordingResource(['1']), $this->dateTime()))->apply();
        $this->assertSame([], $this->db['insert']);
    }

    public function testDefaultTemplatesAreInsertedOnlyWhenMissing(): void
    {
        $resource = $this->recordingResource([false, '9', false]);
        $patch = new InstallDefaultTemplates($this->dataSetup($resource->getConnection()));
        $patch->apply();

        $this->assertSame(['product', 'cms'], array_map(static fn($insert) => $insert[1]['entity_type'], $this->db['insert']));
        $this->assertContains(['entity_type = ?', 'category'], $this->db['where']);
        $this->assertSame(['startSetup', 'endSetup'], $this->setupCalls);
        $this->assertSame([], InstallDefaultTemplates::getDependencies());
        $this->assertSame([], $patch->getAliases());
    }
}
