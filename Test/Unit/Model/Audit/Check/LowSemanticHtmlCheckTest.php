<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\LowSemanticHtmlCheck;

class LowSemanticHtmlCheckTest extends AbstractCheckTestCase
{
    public function testTwoMainLandmarksAreReported(): void
    {
        $issues = (new LowSemanticHtmlCheck($this->catalog()))->check(
            $this->page('low_semantic_html.positive.html', 'https://example.com/faq'),
            $this->ctx()
        );

        $this->assertSame([LowSemanticHtmlCheck::CODE_MAIN], $this->codes($issues));
    }

    public function testDivOnlyPageIsLowSemantic(): void
    {
        $issues = (new LowSemanticHtmlCheck($this->catalog()))->check($this->page('low_semantic_html.divs.html'), $this->ctx());

        $this->assertSame([LowSemanticHtmlCheck::CODE], $this->codes($issues));
        $this->assertSame('landmarks', $issues[0]->element);
    }

    public function testSemanticPagePasses(): void
    {
        $this->assertSame([], (new LowSemanticHtmlCheck($this->catalog()))->check(
            $this->page('low_semantic_html.negative.html'),
            $this->ctx()
        ));
    }
}
