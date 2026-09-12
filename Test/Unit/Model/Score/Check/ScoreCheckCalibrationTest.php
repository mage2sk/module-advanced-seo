<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Score\Check;

use Panth\AdvancedSEO\Model\Score\Check\AiOverviewCheck;
use Panth\AdvancedSEO\Model\Score\Check\KeywordCheck;
use Panth\AdvancedSEO\Model\Score\Check\ReadabilityCheck;
use PHPUnit\Framework\TestCase;

class ScoreCheckCalibrationTest extends TestCase
{
    private const ENGLISH_BODY = 'The Compete Track Tote is a durable gym bag built for daily training use. '
        . 'It holds a towel, a jacket and street shoes with room to spare inside. '
        . 'Two way zippers open the main compartment wide so packing stays quick. '
        . 'Water bottles slide into the easy access external pockets on each side. '
        . 'The contrast detailing keeps the bag looking smart after heavy use. '
        . 'It measures twenty two inches wide by seventeen inches high by ten inches deep. '
        . 'The bag weighs one point two pounds when empty and carries a two year warranty. '
        . 'Returns are free within thirty days of delivery for every order placed online. '
        . 'Riders and runners both use this bag for daily gym trips and weekend travel. '
        . 'The handles are stitched twice so they hold a full load without stretching. '
        . 'A zipped inner pocket keeps keys and a wallet separate from damp kit. '
        . 'The base panel is reinforced so the bag stands upright on a locker room floor. '
        . 'Every seam is bound to stop fraying over years of repeated heavy use here.';

    public function testReadabilityIsNotScoredForNonLatinContent(): void
    {
        $result = (new ReadabilityCheck())->run([
            'content' => 'Здравствуйте, у меня есть вопрос о доставке моего заказа в Москву. '
                . 'Пожалуйста, свяжитесь со мной по электронной почте когда сможете помочь мне.',
        ]);

        $this->assertSame(0.0, $result['max'], 'Flesch Reading Ease does not apply to non-Latin script');
        $this->assertStringContainsString('not predominantly Latin', $result['message']);
    }

    public function testReadabilityIsNotScoredForVeryShortText(): void
    {
        $result = (new ReadabilityCheck())->run(['content' => 'A short product blurb.']);

        $this->assertSame(0.0, $result['max']);
        $this->assertStringContainsString('too short', $result['message']);
    }

    public function testReadabilityIsScoredForRealEnglishProse(): void
    {
        $result = (new ReadabilityCheck())->run(['content' => self::ENGLISH_BODY]);

        $this->assertSame(100.0, $result['max']);
        $this->assertGreaterThan(0.0, $result['score']);
    }

    public function testMissingMetaKeywordsAreNotPenalised(): void
    {
        $result = (new KeywordCheck())->run(['meta' => ['keywords' => '', 'title' => 'A title'], 'content' => 'body']);

        $this->assertSame(0.0, $result['max'], 'search engines ignore meta keywords, absence must not cost points');
        $this->assertStringContainsString('not scored', $result['message']);
    }

    public function testMetaKeywordsAreStillScoredWhenTheMerchantSetsThem(): void
    {
        $result = (new KeywordCheck())->run([
            'meta' => ['keywords' => 'gym bag', 'title' => 'Gym bag for training', 'description' => 'A gym bag'],
            'content' => 'This gym bag is a great gym bag for the gym.',
        ]);

        $this->assertSame(100.0, $result['max']);
        $this->assertGreaterThan(0.0, $result['score']);
    }

    public function testKeywordDensityWorksOnNonAsciiContent(): void
    {
        $result = (new KeywordCheck())->run([
            'meta' => ['keywords' => 'сумка', 'title' => 'Спортивная сумка', 'description' => 'Купить сумка'],
            'content' => 'Эта сумка очень удобная. Сумка сделана из прочной ткани и служит долго.',
        ]);

        $this->assertSame(100.0, $result['max']);
        $density = $result['details']['сумка']['density'] ?? null;
        $this->assertNotNull($density);
        $this->assertLessThan(100.0, $density, 'ASCII word counting would have produced a nonsense density');
    }

    public function testAiOverviewIsNotScoredForShortProductCopy(): void
    {
        $result = (new AiOverviewCheck())->run(['content' => '<p>A short product description of only a few words.</p>']);

        $this->assertSame(0.0, $result['max'], 'short product copy must not be judged against a long-form rubric');
        $this->assertStringContainsString('too short to assess', $result['message']);
    }

    public function testAiOverviewIsScoredForLongFormContent(): void
    {
        $result = (new AiOverviewCheck())->run([
            'content' => '<h2>Overview</h2><p>' . self::ENGLISH_BODY . '</p><ul><li>Dual handles</li><li>Two way zippers</li></ul>',
        ]);

        $this->assertSame(100.0, $result['max']);
        $this->assertGreaterThan(0.0, $result['score']);
    }
}
