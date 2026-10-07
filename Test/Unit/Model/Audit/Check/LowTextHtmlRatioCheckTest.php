<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\LowTextHtmlRatioCheck;

class LowTextHtmlRatioCheckTest extends AbstractCheckTestCase
{
    public function testInlineStyleHeavyPageIsFlaggedWithTopContributors(): void
    {
        $issues = (new LowTextHtmlRatioCheck($this->catalog()))->check(
            $this->page('low_text_html_ratio.positive.html'),
            $this->ctx()
        );

        $this->assertSame([LowTextHtmlRatioCheck::CODE], $this->codes($issues));
        $this->assertStringContainsString('inline style', $issues[0]->detail);
        $this->assertStringContainsString('inline svg', $issues[0]->detail);
    }

    public function testTextRichPagePasses(): void
    {
        $this->assertSame([], (new LowTextHtmlRatioCheck($this->catalog()))->check(
            $this->page('low_text_html_ratio.negative.html'),
            $this->ctx()
        ));
    }
}
