<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\HreflangConflictCheck;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class HreflangConflictCheckTest extends AbstractCheckTestCase
{
    public function testEnUsTopicClusterIsAConflict(): void
    {
        $page   = $this->page('hreflang_conflict.positive.html', 'https://example.com/service-a');
        $issues = (new HreflangConflictCheck($this->catalog()))->check($page, $this->ctx());

        $elements = $this->elements($issues);
        $this->assertContains('hreflang=en-us', $elements);
        $this->assertContains('hreflang=english', $elements);
        $this->assertContains('hreflang self-reference', $elements);
    }

    public function testTranslationGroupWithSelfReferencePasses(): void
    {
        $page   = $this->page('hreflang_conflict.negative.html', 'https://example.com/service-a');
        $issues = (new HreflangConflictCheck($this->catalog()))->check($page, $this->ctx());

        $this->assertSame([], $issues);
    }

    public function testNonOkAlternateIsFlagged(): void
    {
        $page   = $this->page('hreflang_conflict.negative.html', 'https://example.com/service-a');
        $issues = (new HreflangConflictCheck($this->catalog()))->check(
            $page,
            $this->ctx(['statuses' => ['https://example.com/de/service-a' => 404]])
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('HTTP 404', $issues[0]->detail);
    }

    public function testPagesDeclaringEachOtherAsTheSameLocaleAndMissingReturnLinks(): void
    {
        $a = new ParsedPage('https://example.com/service-a', 200, hreflang: [
            ['locale' => 'en-US', 'href' => 'https://example.com/service-b'],
            ['locale' => 'en-US', 'href' => 'https://example.com/service-a'],
        ]);
        $b = new ParsedPage('https://example.com/service-b', 200, hreflang: [
            ['locale' => 'en-US', 'href' => 'https://example.com/service-a'],
            ['locale' => 'en-US', 'href' => 'https://example.com/service-b'],
        ]);
        $c = new ParsedPage('https://example.com/contact-us', 200, hreflang: [
            ['locale' => 'de-DE', 'href' => 'https://example.com/service-a'],
        ]);
        $pages  = [$a->url => $a, $b->url => $b, $c->url => $c];
        $issues = (new HreflangConflictCheck($this->catalog()))->checkSite($pages, $this->ctx(['pages' => $pages]));
        $details = implode(' | ', array_map(static fn ($i) => $i->detail, $issues));

        $this->assertStringContainsString('declare each other as en-us', $details);
        $this->assertStringContainsString('Missing return link', $details);
    }

    public function testLocaleValidation(): void
    {
        $this->assertTrue(HreflangConflictCheck::isValidLocale('en'));
        $this->assertTrue(HreflangConflictCheck::isValidLocale('en-GB'));
        $this->assertTrue(HreflangConflictCheck::isValidLocale('x-default'));
        $this->assertFalse(HreflangConflictCheck::isValidLocale('english'));
        $this->assertFalse(HreflangConflictCheck::isValidLocale('en_GB'));
    }
}
