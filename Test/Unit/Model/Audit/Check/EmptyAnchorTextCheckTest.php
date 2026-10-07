<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\EmptyAnchorTextCheck;

class EmptyAnchorTextCheckTest extends AbstractCheckTestCase
{
    public function testIconOnlyAndBlankLinksAreReported(): void
    {
        $issues = (new EmptyAnchorTextCheck($this->catalog()))->check($this->page('empty_anchor_text.positive.html'), $this->ctx());

        $this->assertSame(['https://example.com/wishlist-share', 'https://example.com/empty'], $this->elements($issues));
    }

    public function testAriaLabelTitleAndImageAltCount(): void
    {
        $this->assertSame([], (new EmptyAnchorTextCheck($this->catalog()))->check(
            $this->page('empty_anchor_text.negative.html'),
            $this->ctx()
        ));
    }
}
