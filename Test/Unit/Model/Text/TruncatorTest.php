<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Text;

use Panth\AdvancedSEO\Model\Text\Truncator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TruncatorTest extends TestCase
{
    private Truncator $truncator;

    protected function setUp(): void
    {
        $this->truncator = new Truncator();
    }

    public function testShortValueIsReturnedUntouched(): void
    {
        $value = 'Stainless Steel Bottle';
        $this->assertSame($value, $this->truncator->truncate($value, 60));
    }

    public function testValueExactlyAtTheLimitIsReturnedUntouched(): void
    {
        $value = str_repeat('a', 60);
        $this->assertSame($value, $this->truncator->truncate($value, 60));
    }

    public function testLongValueCutsOnAWordBoundary(): void
    {
        $result = $this->truncator->truncate('Stainless Steel Water Bottle with Vacuum Insulation and Lid', 45);
        $this->assertSame('Stainless Steel Water Bottle with Vacuum...', $result);
        $this->assertLessThanOrEqual(45, mb_strlen($result, 'UTF-8'));
    }

    public function testAWordIsOnlySplitWhenBacktrackingWouldDropMostOfTheBudget(): void
    {
        $value = 'Stainless Steel Water Bottle with Vacuum Insulation and Lid';
        for ($max = 10; $max <= 60; $max++) {
            $result = $this->truncator->truncate($value, $max);
            $this->assertLessThanOrEqual($max, mb_strlen($result, 'UTF-8'), 'max ' . $max . ' overshot');
            if (!str_ends_with($result, '...')) {
                continue;
            }
            $kept = substr($result, 0, -3);
            $this->assertTrue(
                str_starts_with($value, $kept),
                'max ' . $max . ' produced a prefix that is not in the source: ' . $kept
            );
            $next = substr($value, strlen($kept), 1);
            if ($next === ' ' || $next === '') {
                continue;
            }
            $budget = $max - 3;
            $lastSpace = strrpos(substr($value, 0, $budget), ' ');
            $this->assertLessThan(
                (int) floor($budget * 0.6),
                $lastSpace === false ? -1 : $lastSpace,
                'max ' . $max . ' cut mid word while a usable space was available: ' . $result
            );
        }
    }

    public function testUnbrokenTokenStillTruncatesAtTheHardLimit(): void
    {
        $value = str_repeat('x', 200);
        $result = $this->truncator->truncate($value, 60);
        $this->assertSame(str_repeat('x', 57) . '...', $result);
        $this->assertSame(60, mb_strlen($result, 'UTF-8'));
    }

    public function testEarlySpaceIsIgnoredSoTheValueDoesNotCollapse(): void
    {
        $this->assertSame(
            'ab yy...',
            $this->truncator->truncate('ab ' . str_repeat('y', 40), 8)
        );
    }

    public function testLateSpaceIsUsed(): void
    {
        $this->assertSame(
            'abcdefgh...',
            $this->truncator->truncate('abcdefgh ijklmnop', 14)
        );
    }

    public static function multibyteValues(): array
    {
        return [
            'devanagari' => ['स्टेनलेस स्टील की पानी की बोतल वैक्यूम इन्सुलेशन के साथ'],
            'accented latin' => ['Bouteille isotherme en acier inoxydable avec bouchon a vis'],
            'cjk' => ['ステンレス製真空断熱ウォーターボトルふた付き'],
        ];
    }

    #[DataProvider('multibyteValues')]
    public function testMultibyteValuesCutOnCharacterBoundaries(string $value): void
    {
        for ($max = 10; $max <= 70; $max++) {
            $result = $this->truncator->truncate($value, $max);
            $this->assertTrue(
                mb_check_encoding($result, 'UTF-8'),
                'max ' . $max . ' produced invalid UTF-8'
            );
            $this->assertLessThanOrEqual(
                $max,
                mb_strlen($result, 'UTF-8'),
                'max ' . $max . ' overshot the limit'
            );
        }
    }

    #[DataProvider('multibyteValues')]
    public function testMultibyteValueUnderTheLimitIsNeverCut(string $value): void
    {
        $max = mb_strlen($value, 'UTF-8') + 5;
        $this->assertSame($value, $this->truncator->truncate($value, $max));
    }

    public function testEmptyValueAndTinyLimitsAreLeftAlone(): void
    {
        $this->assertSame('', $this->truncator->truncate('', 60));
        $this->assertSame('a long value', $this->truncator->truncate('a long value', 3));
        $this->assertSame('a long value', $this->truncator->truncate('a long value', 0));
    }

    public function testTrailingPunctuationIsTrimmedBeforeTheEllipsis(): void
    {
        $this->assertSame(
            'Bottles, flasks...',
            $this->truncator->truncate('Bottles, flasks, mugs and tumblers', 20)
        );
    }
}
