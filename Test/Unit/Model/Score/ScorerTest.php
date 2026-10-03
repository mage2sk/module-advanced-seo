<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Score;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AdvancedSEO\Api\Data\SeoScoreInterface;
use Panth\AdvancedSEO\Model\ResourceModel\Score as ScoreResource;
use Panth\AdvancedSEO\Model\Score\CheckInterface;
use Panth\AdvancedSEO\Model\Score\ContextBuilder;
use Panth\AdvancedSEO\Model\Score\GradeCalculator;
use Panth\AdvancedSEO\Model\Score\Scorer;
use Panth\AdvancedSEO\Model\Score\SeoScore;
use Panth\AdvancedSEO\Model\Score\SeoScoreFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScorerTest extends TestCase
{
    private function check(array|\Throwable $result): CheckInterface
    {
        $check = $this->createStub(CheckInterface::class);
        if ($result instanceof \Throwable) {
            $check->method('run')->willThrowException($result);
        } else {
            $check->method('run')->willReturn($result);
        }

        return $check;
    }

    private function scorer(
        array $checks,
        ?AdapterInterface $connection = null,
        ?LoggerInterface $logger = null,
        ?ContextBuilder $contextBuilder = null
    ): Scorer {
        if ($contextBuilder === null) {
            $contextBuilder = $this->createStub(ContextBuilder::class);
            $contextBuilder->method('build')->willReturn(['entity_type' => 'product']);
        }
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection ?? $this->createStub(AdapterInterface::class));
        $resource->method('getTableName')->willReturnArgument(0);
        $factory = $this->createStub(SeoScoreFactory::class);
        $factory->method('create')->willReturnCallback(static fn () => new SeoScore());
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-10-01 12:00:00');

        return new Scorer(
            $contextBuilder,
            $this->createStub(ScoreResource::class),
            $resource,
            new Json(),
            $dateTime,
            $logger ?? $this->createStub(LoggerInterface::class),
            $factory,
            new GradeCalculator(),
            $checks
        );
    }

    public function testFailingCheckIsLoggedAndSkipped(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'Panth SEO score check failed',
            ['code' => 'broken', 'error' => 'kaput']
        );

        $score = $this->scorer([
            'broken' => $this->check(new \RuntimeException('kaput')),
            'good'   => $this->check(['score' => 9, 'max' => 10, 'message' => 'fine', 'details' => ['x' => 1]]),
        ], null, $logger)->score('product', 4, 1);

        $this->assertSame(90, $score->getScore());
        $this->assertSame(['good'], array_keys($score->getBreakdown()));
        $this->assertSame(['x' => 1], $score->getBreakdown()['good']['details']);
        $this->assertSame(1.0, $score->getBreakdown()['good']['weight']);
        $this->assertSame([], $score->getIssues());
    }

    public function testScoresAreClampedAndDefaultsApplied(): void
    {
        $score = $this->scorer([
            'over'  => $this->check(['score' => 150]),
            'under' => $this->check(['score' => -20, 'max' => 100]),
        ])->score('category', 2, 3);

        $this->assertSame(100.0, $score->getBreakdown()['over']['score']);
        $this->assertSame(0.0, $score->getBreakdown()['under']['score']);
        $this->assertSame('', $score->getBreakdown()['over']['message']);
        $this->assertSame([], $score->getBreakdown()['over']['details']);
        $this->assertSame(50, $score->getScore());
        $this->assertSame([['check' => 'under', 'score' => 0.0, 'message' => '']], $score->getIssues());
        $this->assertSame('category', $score->getEntityType());
        $this->assertSame(2, $score->getEntityId());
        $this->assertSame(3, $score->getStoreId());
    }

    public function testNoChecksMeansZeroScore(): void
    {
        $score = $this->scorer([])->score('product', 1, 1);

        $this->assertSame(0, $score->getScore());
        $this->assertSame([], $score->getBreakdown());
        $this->assertSame((new GradeCalculator())->forScore(0), $score->getGrade());
    }

    public function testScoreIsPersistedWithUpsert(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('insertOnDuplicate')->with(
            'panth_seo_score',
            $this->callback(function (array $row): bool {
                $this->assertSame('cms_page', $row[SeoScoreInterface::ENTITY_TYPE]);
                $this->assertSame(8, $row[SeoScoreInterface::ENTITY_ID]);
                $this->assertSame(80, $row[SeoScoreInterface::SCORE]);
                $this->assertSame('2026-10-01 12:00:00', $row[SeoScoreInterface::COMPUTED_AT]);
                $this->assertSame(80, (int) json_decode($row[SeoScoreInterface::BREAKDOWN], true)['a']['score']);

                return true;
            }),
            [
                SeoScoreInterface::SCORE,
                SeoScoreInterface::GRADE,
                SeoScoreInterface::BREAKDOWN,
                SeoScoreInterface::COMPUTED_AT,
            ]
        );

        $this->scorer(['a' => $this->check(['score' => 80, 'max' => 100])], $connection)->score('cms_page', 8, 1);
    }

    public function testPersistFailureIsLoggedButScoreReturned(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insertOnDuplicate')->willThrowException(new \RuntimeException('read only'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Panth SEO score persist failed: read only');

        $score = $this->scorer(['a' => $this->check(['score' => 70, 'max' => 100])], $connection, $logger)
            ->score('product', 1, 1);

        $this->assertSame(70, $score->getScore());
    }

    public function testScoreBatchScoresEveryId(): void
    {
        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->expects($this->exactly(2))->method('build')->willReturn([]);

        $result = $this->scorer(['a' => $this->check(['score' => 1, 'max' => 1])], null, null, $contextBuilder)
            ->scoreBatch('product', ['3', 5], 1);

        $this->assertSame([3, 5], array_keys($result));
        $this->assertSame(5, $result[5]->getEntityId());
        $this->assertSame(100, $result[3]->getScore());
    }
}
