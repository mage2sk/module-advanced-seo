<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\NofollowInternalLinkCheck;

class NofollowInternalLinkCheckTest extends AbstractCheckTestCase
{
    public function testContentLinkIsAWarningAndCartAndFilterLinksAreExpected(): void
    {
        $issues = (new NofollowInternalLinkCheck($this->catalog()))->check(
            $this->page('nofollow_internal_link.positive.html'),
            $this->ctx()
        );

        $this->assertSame(['https://example.com/service-a'], $this->elements($issues, NofollowInternalLinkCheck::CODE));
        $allowed = $this->elements($issues, NofollowInternalLinkCheck::CODE_ALLOWED);
        $this->assertCount(3, $allowed);
        $this->assertContains('https://example.com/checkout/cart/', $allowed);
        $this->assertContains('https://example.com/bags/license_type/free.html', $allowed);
        $this->assertContains('https://example.com/bags.html?price=10-20', $allowed);
    }

    public function testFollowedInternalAndNofollowExternalLinksPass(): void
    {
        $issues = (new NofollowInternalLinkCheck($this->catalog()))->check(
            $this->page('nofollow_internal_link.negative.html'),
            $this->ctx()
        );

        $this->assertSame([], $issues);
    }
}
