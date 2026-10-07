<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Panth\AdvancedSEO\Model\Audit\RobotsTxt;
use PHPUnit\Framework\TestCase;

class RobotsTxtTest extends TestCase
{
    private const CONTENT = "Content-Signal: global=yes\r\n"
        . "# leading comment\r\n"
        . "User-agent: *\r\n"
        . "Disallow: /checkout # trailing comment\r\n"
        . "Allow: /checkout/cart$\r\n"
        . "Disallow: /*?sort=\r\n"
        . "Disallow:\r\n"
        . "Content-Signal: search=yes\r\n"
        . "this line has no colon\r\n"
        . "\r\n"
        . "User-agent: GPTBot\r\n"
        . "User-agent: ClaudeBot\r\n"
        . "Disallow: /\r\n"
        . "Allow: /public\r\n"
        . "Disallow: /public\r\n"
        . "Sitemap: https://example.com/sitemap.xml\r\n"
        . "Content-Signal: ai-train=no\n";

    private function robots(): RobotsTxt
    {
        return new RobotsTxt(self::CONTENT);
    }

    public function testGroupsSitemapsAndSignalsAreParsed(): void
    {
        $robots = $this->robots();

        $this->assertSame(self::CONTENT, $robots->getContent());
        $this->assertCount(2, $robots->getGroups());
        $this->assertSame(['gptbot', 'claudebot'], $robots->getGroups()[1]['agents']);
        $this->assertSame(['https://example.com/sitemap.xml'], $robots->getSitemaps());
        $this->assertSame(['global=yes', 'search=yes', 'ai-train=no'], $robots->getContentSignals());
        $this->assertTrue($robots->hasContentSignal());
        $this->assertSame(['search=yes'], $robots->getGroups()[0]['contentSignals']);
        $this->assertSame(['ai-train=no'], $robots->getGroups()[1]['contentSignals']);
        $this->assertSame(
            [['disallow', '/checkout'], ['allow', '/checkout/cart$'], ['disallow', '/*?sort='], ['disallow', '']],
            $robots->getGroups()[0]['rules']
        );
    }

    public function testGroupLookupFallsBackToWildcard(): void
    {
        $robots = $this->robots();

        $this->assertSame(['gptbot', 'claudebot'], $robots->explicitGroupFor('ClaudeBot')['agents']);
        $this->assertNull($robots->explicitGroupFor('Googlebot'));
        $this->assertSame(['*'], $robots->groupFor('Googlebot')['agents']);
    }

    public function testLongestMatchWinsAndAnchorsAreRespected(): void
    {
        $robots = $this->robots();

        $this->assertFalse($robots->isAllowed('Googlebot', '/checkout/payment'));
        $this->assertTrue($robots->isAllowed('Googlebot', '/checkout/cart'));
        $this->assertFalse($robots->isAllowed('Googlebot', '/checkout/cart/add'));
        $this->assertFalse($robots->isAllowed('Googlebot', '/list?sort=price'));
        $this->assertTrue($robots->isAllowed('Googlebot', '/about'));
        $this->assertFalse($robots->isAllowed('GPTBot', '/about'));
    }

    public function testAllowWinsATieOfEqualLength(): void
    {
        $this->assertTrue($this->robots()->isAllowed('gptbot', '/public/page'));
    }

    public function testMatchingDisallowReturnsTheFirstMatchingPattern(): void
    {
        $robots = $this->robots();

        $this->assertSame('/checkout', $robots->matchingDisallow('Googlebot', '/checkout/x'));
        $this->assertSame('', $robots->matchingDisallow('Googlebot', '/about'));
        $this->assertSame('/', $robots->matchingDisallow('GPTBot', '/anything'));
    }

    public function testEmptyFileAllowsEverything(): void
    {
        $robots = new RobotsTxt('');

        $this->assertSame([], $robots->getGroups());
        $this->assertFalse($robots->hasContentSignal());
        $this->assertNull($robots->groupFor('Googlebot'));
        $this->assertTrue($robots->isAllowed('Googlebot', '/admin'));
        $this->assertSame('', $robots->matchingDisallow('Googlebot', '/admin'));
    }

    public function testRulesBeforeAnyUserAgentAreIgnored(): void
    {
        $robots = new RobotsTxt("Disallow: /\nUser-agent: *\nDisallow: /private\n");

        $this->assertTrue($robots->isAllowed('bot', '/'));
        $this->assertFalse($robots->isAllowed('bot', '/private/a'));
    }
}
