<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Score\Check;

use Panth\AdvancedSEO\Model\Score\Check\AiOverviewCheck;
use PHPUnit\Framework\TestCase;

class AiOverviewCheckTest extends TestCase
{
    private static function words(int $count, string $word = 'word'): string
    {
        return implode(' ', array_fill(0, $count, $word));
    }

    public function testCode(): void
    {
        $this->assertSame('ai_overview', (new AiOverviewCheck())->getCode());
    }

    public function testOptimalPassageAndFactualOpening(): void
    {
        $result = (new AiOverviewCheck())->run(['content' => '<p>' . self::words(140) . '</p>']);

        $this->assertSame(30.0, $result['score']);
        $this->assertSame(100.0, $result['max']);
        $this->assertSame('AI Overview score 30/100: found semantic units, factual opening', $result['message']);
        $this->assertSame(1, $result['details']['semantic_unit']['found']);
        $this->assertTrue($result['details']['factual_opening']['factual']);
    }

    public function testNearMissPassageEarnsHalfPoints(): void
    {
        $result = (new AiOverviewCheck())->run([
            'content' => '<p>' . self::words(110) . '</p><p>' . self::words(20) . '</p>',
        ]);

        $this->assertSame(10.0, $result['details']['semantic_unit']['points']);
        $this->assertSame(20.0, $result['score']);
    }

    public function testMarketingOpeningEarnsNothing(): void
    {
        $result = (new AiOverviewCheck())->run(['content' => '<p>Discover ' . self::words(139) . '</p>']);

        $this->assertSame(0.0, $result['details']['factual_opening']['points']);
        $this->assertSame('Opening uses marketing language', $result['details']['factual_opening']['message']);
        $this->assertSame(20.0, $result['score']);
    }

    public function testNoSignalsMessage(): void
    {
        $content = 'Discover word word word<br>' . str_repeat('word word word word<br/>', 30);

        $result = (new AiOverviewCheck())->run(['content' => $content]);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('AI Overview score 0/100: no AI-optimised content signals detected', $result['message']);
    }

    public function testRichContentCollectsEverySignal(): void
    {
        $content = '<h2>Overview</h2>'
            . '<p>' . self::words(140) . '</p>'
            . '<p>Lighter compared to the old model, 5 kg and 10 cm, pure cotton. Rated 4.5/5.</p>'
            . '<ul><li>one</li><li>two</li><li>three</li></ul>'
            . '<h2>Questions</h2>'
            . '<h3>What is it?</h3><h3>How big is it?</h3><h3>Why buy it?</h3>'
            . '<table><tr><th>Spec</th><td>Value</td></tr></table>';

        $result = (new AiOverviewCheck())->run(['content' => $content, 'brand' => 'Acme']);

        $details = $result['details'];
        $this->assertSame(20.0, $details['semantic_unit']['points']);
        $this->assertSame(15.0, $details['lists']['points']);
        $this->assertSame(15.0, $details['headings']['points']);
        $this->assertSame(15.0, $details['faq']['points']);
        $this->assertSame(3.0, $details['factual_opening']['points']);
        $this->assertSame(15.0, $details['specific_data']['points']);
        $this->assertSame(10.0, $details['comparison']['points']);
        $this->assertSame(
            'Comparison signals: brand, comparison language, specification table, ratings',
            $details['comparison']['message']
        );
        $this->assertSame(93.0, $result['score']);
        $this->assertStringContainsString('found semantic units, lists, headings, FAQ, factual opening, data points, comparisons', $result['message']);
    }

    public function testPartialSignalsEarnPartialPoints(): void
    {
        $content = '<h2>Intro</h2><p>' . self::words(140) . '</p><ul><li>only</li></ul>'
            . '<p>Weighs 2 kg overall.</p><dl><dt>Is it safe?</dt></dl><p>Better than others.</p>';

        $details = (new AiOverviewCheck())->run(['content' => $content])['details'];

        $this->assertSame(7.0, $details['lists']['points']);
        $this->assertSame(8.0, $details['headings']['points']);
        $this->assertSame(8.0, $details['faq']['points']);
        $this->assertSame(5.0, $details['specific_data']['points']);
        $this->assertSame(5.0, $details['comparison']['points']);
    }

    public function testMarkdownListsAndPlainQuestions(): void
    {
        $content = "Steel frame bike for city riding today\n- light frame\n- fast gears\n2) strong brakes\n"
            . self::words(130) . "\nHow does it fold? Easily.";

        $details = (new AiOverviewCheck())->run(['content' => $content])['details'];

        $this->assertSame(15.0, $details['lists']['points']);
        $this->assertSame(3, $details['lists']['found']);
        $this->assertSame(10.0, $details['factual_opening']['points']);
        $this->assertSame(8.0, $details['faq']['points']);
        $this->assertSame(10.0, $details['semantic_unit']['points']);
    }

    public function testTwoDataTypesEarnTenPoints(): void
    {
        $content = '<p>' . self::words(140) . '</p><p>Made of bamboo, weighs 3 kg.</p>';

        $this->assertSame(10.0, (new AiOverviewCheck())->run(['content' => $content])['details']['specific_data']['points']);
    }

    public function testContentFallsBackToMetaThenDescription(): void
    {
        $check = new AiOverviewCheck();

        $fromMeta = $check->run(['content' => '', 'meta' => ['description' => self::words(125)]]);
        $this->assertSame(100.0, $fromMeta['max']);

        $fromDescription = $check->run(['meta' => ['description' => ''], 'description' => self::words(125)]);
        $this->assertSame(100.0, $fromDescription['max']);

        $nothing = $check->run([]);
        $this->assertSame(0.0, $nothing['max']);
        $this->assertSame(0, $nothing['details']['body_words']);
    }

    public function testBrandFromContextAttributesCountsAsComparisonSignal(): void
    {
        $details = (new AiOverviewCheck())->run([
            'content' => '<p>' . self::words(140) . '</p>',
            'attributes' => ['brand' => 'Acme'],
        ])['details'];

        $this->assertSame('Comparison signals: brand', $details['comparison']['message']);
        $this->assertSame(5.0, $details['comparison']['points']);
    }
}
