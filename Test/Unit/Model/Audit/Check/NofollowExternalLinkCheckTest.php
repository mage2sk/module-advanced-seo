<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\NofollowExternalLinkCheck;
use Panth\AdvancedSEO\Model\Audit\Issue;

class NofollowExternalLinkCheckTest extends AbstractCheckTestCase
{
    public function testNofollowIsReportedButSponsoredAndUgcAreNotCounted(): void
    {
        $issues = (new NofollowExternalLinkCheck($this->catalog()))->check(
            $this->page('nofollow_external_link.positive.html'),
            $this->ctx()
        );

        $this->assertSame(['https://partner.example.net/'], $this->elements($issues));
        $this->assertSame(Issue::SEVERITY_NOTICE, $issues[0]->severity);
    }

    public function testFollowedExternalAndNofollowInternalPass(): void
    {
        $this->assertSame([], (new NofollowExternalLinkCheck($this->catalog()))->check(
            $this->page('nofollow_external_link.negative.html'),
            $this->ctx()
        ));
    }
}
