<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Score;

use Panth\AdvancedSEO\Api\SeoScorerInterface;
use Panth\AdvancedSEO\Model\Score\GradeCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GradeCalculatorTest extends TestCase
{
    private GradeCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new GradeCalculator();
    }

    #[DataProvider('boundaryProvider')]
    public function testEveryBoundaryLandsOnTheRightGrade(int $score, string $expected): void
    {
        $this->assertSame($expected, $this->calculator->forScore($score));
    }

    public static function boundaryProvider(): array
    {
        return [
            'zero' => [0, 'F'],
            'F upper' => [59, 'F'],
            'D lower' => [60, 'D'],
            'D upper' => [69, 'D'],
            'C lower' => [70, 'C'],
            'C upper' => [79, 'C'],
            'B lower' => [80, 'B'],
            'B upper' => [89, 'B'],
            'A lower' => [90, 'A'],
            'A upper' => [100, 'A'],
        ];
    }

    #[DataProvider('formerEBandProvider')]
    public function testTheRetiredEBandIsNowF(int $score): void
    {
        $this->assertSame(
            'F',
            $this->calculator->forScore($score),
            'scores in the old 40-59 E band must grade F, the interface defines no E'
        );
    }

    public static function formerEBandProvider(): array
    {
        return [[40], [45], [48], [50], [52], [53], [54], [59]];
    }

    public function testTheSameScoreAlwaysProducesTheSameGrade(): void
    {
        foreach ([10, 40, 50, 53, 65, 95] as $score) {
            $first = $this->calculator->forScore($score);
            for ($i = 0; $i < 5; $i++) {
                $this->assertSame($first, $this->calculator->forScore($score));
            }
        }
    }

    #[DataProvider('outOfRangeProvider')]
    public function testOutOfRangeScoresAreClamped(int $score, string $expected): void
    {
        $this->assertSame($expected, $this->calculator->forScore($score));
    }

    public static function outOfRangeProvider(): array
    {
        return [
            'negative' => [-20, 'F'],
            'above one hundred' => [140, 'A'],
        ];
    }

    public function testOnlyTheInterfaceGradesAreEverReturned(): void
    {
        $allowed = [
            SeoScorerInterface::GRADE_A,
            SeoScorerInterface::GRADE_B,
            SeoScorerInterface::GRADE_C,
            SeoScorerInterface::GRADE_D,
            SeoScorerInterface::GRADE_F,
        ];

        for ($score = -5; $score <= 105; $score++) {
            $this->assertContains($this->calculator->forScore($score), $allowed);
        }
    }

    public function testEveryGradeHasADistinctColour(): void
    {
        $colours = [];
        foreach ([95, 85, 75, 65, 30] as $score) {
            $colours[] = $this->calculator->colorForScore($score);
        }

        $this->assertCount(5, array_unique($colours));
    }

    public function testValidityCheckRejectsTheRetiredGrade(): void
    {
        $this->assertTrue($this->calculator->isValid('A'));
        $this->assertTrue($this->calculator->isValid('f'));
        $this->assertTrue($this->calculator->isValid(' D '));
        $this->assertFalse($this->calculator->isValid('E'));
        $this->assertFalse($this->calculator->isValid(''));
    }

    public function testAllReturnsTheFiveGradesInOrder(): void
    {
        $this->assertSame(['A', 'B', 'C', 'D', 'F'], $this->calculator->all());
    }
}
