<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Score\Check;

use Panth\AdvancedSEO\Model\Score\Check\KeywordCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KeywordCheckTest extends TestCase
{
    private static function content(int $hits, int $total): string
    {
        return implode(' ', array_merge(array_fill(0, $hits, 'shoe'), array_fill(0, $total - $hits, 'x')));
    }

    public function testCode(): void
    {
        $this->assertSame('keyword', (new KeywordCheck())->getCode());
    }

    public function testSeparatorOnlyKeywordsAreNotScored(): void
    {
        $result = (new KeywordCheck())->run(['meta' => ['keywords' => ' , ,, ']]);

        $this->assertSame(0.0, $result['max']);
        $this->assertStringContainsString('not scored', $result['message']);
    }

    public static function densityProvider(): array
    {
        return [
            'ideal density'  => [2, 100, 30.0],
            'low density'    => [1, 300, 15.0],
            'high density'   => [4, 100, 10.0],
            'stuffed'        => [10, 100, 0.0],
            'absent'         => [0, 100, 0.0],
        ];
    }

    #[DataProvider('densityProvider')]
    public function testDensityBands(int $hits, int $total, float $densityPoints): void
    {
        $result = (new KeywordCheck())->run([
            'meta'    => ['keywords' => 'Shoe', 'title' => 'Shoe sale', 'description' => 'Every shoe'],
            'content' => '<p>' . self::content($hits, $total) . '</p>',
        ]);

        $this->assertEqualsWithDelta(70.0 + $densityPoints, $result['score'], 0.0001);
        $this->assertTrue($result['details']['shoe']['in_title']);
        $this->assertTrue($result['details']['shoe']['in_description']);
        $this->assertSame('1 keyword(s) evaluated', $result['message']);
    }

    public function testScoresAreAveragedAcrossKeywords(): void
    {
        $result = (new KeywordCheck())->run([
            'meta'    => ['keywords' => 'shoe, boot', 'title' => 'Shoe sale', 'description' => 'Every shoe'],
            'content' => self::content(2, 100),
        ]);

        $this->assertSame(50.0, $result['score']);
        $this->assertSame(0.0, $result['details']['boot']['score']);
        $this->assertSame(2.0, $result['details']['shoe']['density']);
        $this->assertSame('2 keyword(s) evaluated', $result['message']);
    }

    public function testMultiWordKeywordDensityCountsEachWord(): void
    {
        $content = 'running shoe ' . implode(' ', array_fill(0, 48, 'x'));

        $result = (new KeywordCheck())->run(['meta' => ['keywords' => 'running shoe'], 'content' => $content]);

        $this->assertSame(4.0, $result['details']['running shoe']['density']);
        $this->assertSame(10.0, $result['score']);
    }
}
