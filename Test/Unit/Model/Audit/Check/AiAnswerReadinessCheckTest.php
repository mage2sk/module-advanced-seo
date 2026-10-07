<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\AiAnswerReadinessCheck;

class AiAnswerReadinessCheckTest extends AbstractCheckTestCase
{
    public function testFreeSoftwareTypedAsProductAndMissingFaqAndDateAreNotices(): void
    {
        $issues = (new AiAnswerReadinessCheck($this->catalog()))->check(
            $this->page('ai_answer_readiness.positive.html', 'https://example.com/free-extension.html'),
            $this->ctx()
        );

        $elements = $this->elements($issues);
        $this->assertContains('Product -> SoftwareApplication', $elements);
        $this->assertContains('no FAQ or HowTo block, no visible "last updated" date', $elements);
    }

    public function testFaqPageWithVisibleDatePasses(): void
    {
        $this->assertSame([], (new AiAnswerReadinessCheck($this->catalog()))->check(
            $this->page('ai_answer_readiness.negative.html'),
            $this->ctx()
        ));
    }
}
