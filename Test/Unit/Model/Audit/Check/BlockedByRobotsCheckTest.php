<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\BlockedByRobotsCheck;
use Panth\AdvancedSEO\Model\Audit\Issue;
use Panth\AdvancedSEO\Model\Audit\RobotsTxt;

class BlockedByRobotsCheckTest extends AbstractCheckTestCase
{
    public function testNoindexedContactPageIsAWarningNamingThePathRule(): void
    {
        $issues = (new BlockedByRobotsCheck($this->catalog()))->check(
            $this->page('blocked_by_robots.positive.html', 'https://example.com/contact-us'),
            $this->ctx(['noindexPathRules' => ['/contact*']])
        );

        $this->assertCount(1, $issues);
        $this->assertSame(Issue::SEVERITY_WARNING, $issues[0]->severity);
        $this->assertStringContainsString('/contact*', $issues[0]->detail);
    }

    public function testExpectedPrivatePagesAndQueryUrlsAreNotices(): void
    {
        $check  = new BlockedByRobotsCheck($this->catalog());
        $robots = new RobotsTxt(self::fixture('robots_ai_bots.negative.txt'));

        $cart = $check->check($this->page('blocked_by_robots.negative.html', 'https://example.com/checkout/cart/'), $this->ctx(['robots' => $robots]));
        $this->assertSame([BlockedByRobotsCheck::CODE], $this->codes($cart));
        $this->assertStringStartsWith('Expected', $cart[0]->detail);

        $query = $check->check($this->page('blocked_by_robots.positive.html', 'https://example.com/bags.html?colour=red'), $this->ctx());
        $this->assertSame([BlockedByRobotsCheck::CODE_QUERY], $this->codes($query));
    }

    public function testIndexableAllowedPagePasses(): void
    {
        $robots = new RobotsTxt(self::fixture('robots_ai_bots.negative.txt'));

        $this->assertSame([], (new BlockedByRobotsCheck($this->catalog()))->check(
            $this->page('blocked_by_robots.negative.html', 'https://example.com/about-us'),
            $this->ctx(['robots' => $robots])
        ));
    }
}
