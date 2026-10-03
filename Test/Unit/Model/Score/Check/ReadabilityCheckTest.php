<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Score\Check;

use Panth\AdvancedSEO\Model\Score\Check\ReadabilityCheck;
use PHPUnit\Framework\TestCase;

class ReadabilityCheckTest extends TestCase
{
    public function testCode(): void
    {
        $this->assertSame('readability', (new ReadabilityCheck())->getCode());
    }

    public function testEmptyContentIsNotScored(): void
    {
        $result = (new ReadabilityCheck())->run(['content' => '<p> </p>']);

        $this->assertSame(0.0, $result['max']);
        $this->assertSame('No content to analyse - not scored', $result['message']);
    }

    public function testDigitOnlyContentIsNotTreatedAsLatin(): void
    {
        $result = (new ReadabilityCheck())->run(['content' => str_repeat('123 456 ', 20)]);

        $this->assertSame(0.0, $result['max']);
        $this->assertStringContainsString('not predominantly Latin', $result['message']);
    }

    public function testVeryEasyTextIsCappedAtHalfMarks(): void
    {
        $result = (new ReadabilityCheck())->run(['content' => str_repeat('The cat sat. ', 15)]);

        $this->assertSame(100.0, $result['max']);
        $this->assertSame(100.0, $result['details']['fre']);
        $this->assertSame(50.0, $result['score']);
        $this->assertSame(15, $result['details']['sentences']);
        $this->assertSame(45, $result['details']['words']);
        $this->assertSame(45, $result['details']['syllables']);
        $this->assertSame('Flesch Reading Ease 100.0', $result['message']);
    }

    public function testVeryHardTextScoresZero(): void
    {
        $result = (new ReadabilityCheck())->run(['content' => str_repeat('internationalization ', 35)]);

        $this->assertSame(0.0, $result['details']['fre']);
        $this->assertSame(0.0, $result['score']);
        $this->assertSame(1, $result['details']['sentences']);
        $this->assertSame(280, $result['details']['syllables']);
    }

    public function testMetaDescriptionIsUsedWhenContentIsEmpty(): void
    {
        $result = (new ReadabilityCheck())->run([
            'content' => '',
            'meta'    => ['description' => str_repeat('The dog ran. ', 12)],
        ]);

        $this->assertSame(50.0, $result['score']);
        $this->assertSame(36, $result['details']['words']);
    }
}
