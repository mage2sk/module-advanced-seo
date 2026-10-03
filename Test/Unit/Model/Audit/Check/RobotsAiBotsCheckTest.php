<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\RobotsAiBotsCheck;
use Panth\AdvancedSEO\Model\Audit\Issue;
use Panth\AdvancedSEO\Model\Audit\RobotsTxt;

class RobotsAiBotsCheckTest extends AbstractCheckTestCase
{
    public function testBlockedBotAllowOnlyGroupAndTrainContradictionAreReported(): void
    {
        $robots = new RobotsTxt(self::fixture('robots_ai_bots.positive.txt'));
        $issues = (new RobotsAiBotsCheck($this->catalog()))->checkSite([], $this->ctx(['robots' => $robots]));

        $this->assertSame(['User-agent: ClaudeBot'], $this->elements($issues, RobotsAiBotsCheck::CODE));

        $precedence = array_values(array_filter($issues, static fn ($i) => $i->code === RobotsAiBotsCheck::CODE_PRECEDENCE));
        $this->assertSame('User-agent: gptbot', $precedence[0]->element);
        $this->assertSame(Issue::SEVERITY_ERROR, $precedence[0]->severity);
        $this->assertStringContainsString('/checkout/', $precedence[0]->detail);
        $contradictions = array_filter($precedence, static fn ($i) => $i->severity === Issue::SEVERITY_WARNING);
        $this->assertCount(2, $contradictions);
    }

    public function testStarGroupWithContentSignalPasses(): void
    {
        $robots = new RobotsTxt(self::fixture('robots_ai_bots.negative.txt'));

        $this->assertSame([], (new RobotsAiBotsCheck($this->catalog()))->checkSite([], $this->ctx(['robots' => $robots])));
        $this->assertSame(['https://example.com/sitemap.xml'], $robots->getSitemaps());
        $this->assertFalse($robots->isAllowed('GPTBot', '/checkout/cart'));
        $this->assertTrue($robots->isAllowed('GPTBot', '/service-a'));
    }

    public function testMissingContentSignalIsANotice(): void
    {
        $robots = new RobotsTxt("User-agent: *\nDisallow: /checkout/\n");
        $issues = (new RobotsAiBotsCheck($this->catalog()))->checkSite([], $this->ctx(['robots' => $robots]));

        $this->assertSame(['Content-Signal'], $this->elements($issues));
        $this->assertSame(Issue::SEVERITY_NOTICE, $issues[0]->severity);
    }
}
