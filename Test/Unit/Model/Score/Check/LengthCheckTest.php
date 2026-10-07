<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Score\Check;

use Panth\AdvancedSEO\Model\Score\Check\LengthCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LengthCheckTest extends TestCase
{
    private function evaluate(string $title, string $description): array
    {
        return (new LengthCheck())->run(['meta' => ['title' => $title, 'description' => $description]]);
    }

    public function testCode(): void
    {
        $this->assertSame('length', (new LengthCheck())->getCode());
    }

    public function testOptimalLengthsScoreFull(): void
    {
        $result = $this->evaluate(str_repeat('a', 60), str_repeat('d', 150));

        $this->assertSame(100.0, $result['score']);
        $this->assertSame(100.0, $result['max']);
        $this->assertSame('Title ~432px (60 chars), description 150 chars', $result['message']);
        $this->assertSame(60, $result['details']['title_chars']);
        $this->assertSame(150, $result['details']['description_chars']);
    }

    public function testMissingMetaScoresZero(): void
    {
        $result = (new LengthCheck())->run([]);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame(0.0, $result['details']['title_pixels']);
    }

    public static function rampProvider(): array
    {
        return [
            'short title and description' => [40, 120, 48.888888, 50.0],
            'long title and description'  => [80, 165, 60.0, 35.714285],
            'beyond the hard limits'      => [90, 200, 0.0, 0.0],
            'below the hard minimum'      => [20, 90, 0.0, 0.0],
        ];
    }

    #[DataProvider('rampProvider')]
    public function testScoresRampBetweenOptimalAndHardLimits(
        int $titleChars,
        int $descChars,
        float $titleScore,
        float $descScore
    ): void {
        $result = $this->evaluate(str_repeat('a', $titleChars), str_repeat('d', $descChars));

        $this->assertEqualsWithDelta($titleScore, $result['details']['title_score'], 0.0001);
        $this->assertEqualsWithDelta($descScore, $result['details']['description_score'], 0.0001);
        $this->assertEqualsWithDelta(($titleScore + $descScore) / 2, $result['score'], 0.0001);
    }

    public function testWideAndNarrowCharactersChangeThePixelEstimate(): void
    {
        $wide   = $this->evaluate('WWWWWWWWWW', '');
        $narrow = $this->evaluate('iiiiiiiiii', '');
        $normal = $this->evaluate('aaaaaaaaaa', '');

        $this->assertEqualsWithDelta(115.2, $wide['details']['title_pixels'], 0.0001);
        $this->assertEqualsWithDelta(39.6, $narrow['details']['title_pixels'], 0.0001);
        $this->assertEqualsWithDelta(72.0, $normal['details']['title_pixels'], 0.0001);
    }

    public function testMultibyteTitlesCountCharactersNotBytes(): void
    {
        $result = $this->evaluate(str_repeat("\u{00e9}", 10), str_repeat("\u{00fc}", 145));

        $this->assertSame(10, $result['details']['title_chars']);
        $this->assertSame(145, $result['details']['description_chars']);
        $this->assertSame(100.0, $result['details']['description_score']);
    }
}
