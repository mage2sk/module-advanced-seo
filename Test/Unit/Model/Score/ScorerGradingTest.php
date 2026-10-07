<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Score;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AdvancedSEO\Api\SeoScorerInterface;
use Panth\AdvancedSEO\Model\ResourceModel\Score as ScoreResource;
use Panth\AdvancedSEO\Model\Score\CheckInterface;
use Panth\AdvancedSEO\Model\Score\ContextBuilder;
use Panth\AdvancedSEO\Model\Score\GradeCalculator;
use Panth\AdvancedSEO\Model\Score\Scorer;
use Panth\AdvancedSEO\Model\Score\SeoScore;
use Panth\AdvancedSEO\Model\Score\SeoScoreFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScorerGradingTest extends TestCase
{
    private function check(float $score, float $max, string $message = 'msg'): CheckInterface
    {
        $check = $this->createStub(CheckInterface::class);
        $check->method('run')->willReturn(['score' => $score, 'max' => $max, 'message' => $message]);

        return $check;
    }

    private function scorer(array $checks, array $weights): Scorer
    {
        $contextBuilder = $this->createStub(ContextBuilder::class);
        $contextBuilder->method('build')->willReturn(['entity_type' => 'product', 'entity_id' => 1, 'store_id' => 1]);

        $connection = $this->createStub(AdapterInterface::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturn('panth_seo_score');

        $factory = $this->createStub(SeoScoreFactory::class);
        $factory->method('create')->willReturnCallback(static fn() => new SeoScore());

        return new Scorer(
            $contextBuilder,
            $this->createStub(ScoreResource::class),
            $resource,
            new Json(),
            $this->createStub(DateTime::class),
            $this->createStub(LoggerInterface::class),
            $factory,
            new GradeCalculator(),
            $checks,
            $weights
        );
    }

    #[DataProvider('gradeProvider')]
    public function testGradeScaleMatchesTheInterface(float $checkScore, string $expectedGrade): void
    {
        $scorer = $this->scorer(['only' => $this->check($checkScore, 100.0)], ['only' => 1.0]);

        $result = $scorer->score('product', 1, 1);

        $this->assertSame($expectedGrade, $result->getGrade());
        $this->assertContains($result->getGrade(), [
            SeoScorerInterface::GRADE_A,
            SeoScorerInterface::GRADE_B,
            SeoScorerInterface::GRADE_C,
            SeoScorerInterface::GRADE_D,
            SeoScorerInterface::GRADE_F,
        ], 'the scorer must never emit a grade the interface does not define');
    }

    public static function gradeProvider(): array
    {
        return [
            'A' => [95.0, 'A'],
            'A boundary' => [90.0, 'A'],
            'B' => [85.0, 'B'],
            'C' => [75.0, 'C'],
            'D' => [65.0, 'D'],
            'F just under D' => [59.0, 'F'],
            'former E band is now F' => [45.0, 'F'],
            'F' => [10.0, 'F'],
        ];
    }

    public function testChecksThatCannotEvaluateAreExcludedFromTheAverage(): void
    {
        $scorer = $this->scorer(
            [
                'good' => $this->check(100.0, 100.0),
                'skipped' => $this->check(0.0, 0.0, 'not scored'),
            ],
            ['good' => 1.0, 'skipped' => 5.0]
        );

        $result = $scorer->score('product', 1, 1);

        $this->assertSame(100, $result->getScore(), 'a check that cannot evaluate must not drag the score down');
        $this->assertArrayNotHasKey('skipped', $result->getBreakdown());
    }

    public function testFailingChecksAreReportedAsIssues(): void
    {
        $scorer = $this->scorer(
            [
                'good' => $this->check(90.0, 100.0, 'all good'),
                'bad' => $this->check(20.0, 100.0, 'title missing'),
            ],
            ['good' => 1.0, 'bad' => 1.0]
        );

        $result = $scorer->score('product', 1, 1);
        $issues = $result->getIssues();

        $this->assertCount(1, $issues);
        $this->assertSame('bad', $issues[0]['check']);
        $this->assertSame('title missing', $issues[0]['message']);
    }

    public function testWeightsAreApplied(): void
    {
        $scorer = $this->scorer(
            ['heavy' => $this->check(100.0, 100.0), 'light' => $this->check(0.0, 100.0)],
            ['heavy' => 3.0, 'light' => 1.0]
        );

        $this->assertSame(75, $scorer->score('product', 1, 1)->getScore());
    }
}
