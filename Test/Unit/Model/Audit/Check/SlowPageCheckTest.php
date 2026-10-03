<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\SlowPageCheck;

class SlowPageCheckTest extends AbstractCheckTestCase
{
    public function testSlowPageReportsTotalAndTtfbSeparately(): void
    {
        $page   = $this->page('slow_page.positive.html', totalMs: 3400.0);
        $issues = (new SlowPageCheck($this->catalog()))->check($page, $this->ctx());

        $this->assertSame([SlowPageCheck::CODE], $this->codes($issues));
        $this->assertStringContainsString('Total 3.40 s', $issues[0]->detail);
        $this->assertStringContainsString('TTFB', $issues[0]->detail);
    }

    public function testLargeHtmlIsFlagged(): void
    {
        $page   = $this->page('slow_page.negative.html', totalMs: 400.0);
        $issues = (new SlowPageCheck($this->catalog()))->check($page, $this->ctx(['largeHtmlBytes' => 100]));

        $this->assertSame([SlowPageCheck::CODE_LARGE], $this->codes($issues));
    }

    public function testFastSmallPagePasses(): void
    {
        $page = $this->page('slow_page.negative.html', totalMs: 900.0);

        $this->assertSame([], (new SlowPageCheck($this->catalog()))->check($page, $this->ctx()));
    }
}
