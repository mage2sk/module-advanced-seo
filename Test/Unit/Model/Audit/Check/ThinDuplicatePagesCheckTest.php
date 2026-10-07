<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\ThinDuplicatePagesCheck;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class ThinDuplicatePagesCheckTest extends AbstractCheckTestCase
{
    private static function text(int $words, string $word = 'delivery'): string
    {
        return implode(' ', array_fill(0, $words, $word));
    }

    private function checkPages(array $pages): array
    {
        $keyed = [];
        foreach ($pages as $page) {
            $keyed[$page->url] = $page;
        }

        return (new ThinDuplicatePagesCheck($this->catalog()))->checkSite($keyed, $this->ctx());
    }

    public function testAllThinMembersOfOneHubAreReported(): void
    {
        $body = self::text(30);
        $hub  = new ParsedPage('https://example.com/faq', 200, mainText: 'FAQ intro ' . $body . ' and more');
        $a    = new ParsedPage('https://example.com/faq/a', 200, h1: ['Question A'], mainText: 'Question A ' . $body);
        $b    = new ParsedPage('https://example.com/faq/b', 200, mainText: strtoupper($body));

        $issues = $this->checkPages([$hub, $a, $b]);

        $this->assertSame(['https://example.com/faq/a', 'https://example.com/faq/b'], array_map(static fn ($i) => $i->url, $issues));
        $this->assertSame(['https://example.com/faq', 'https://example.com/faq'], $this->elements($issues));
        $this->assertStringContainsString('Main text (30 words) is contained in https://example.com/faq, which 2 thin page(s)', $issues[0]->detail);
    }

    public function testWordCountBoundsAreRespected(): void
    {
        $hub = new ParsedPage('https://example.com/hub', 200, mainText: self::text(400));

        $this->assertSame([], $this->checkPages([
            $hub,
            new ParsedPage('https://example.com/tiny', 200, mainText: self::text(19)),
            new ParsedPage('https://example.com/long', 200, mainText: self::text(151)),
        ]));
        $this->assertCount(1, $this->checkPages([
            $hub,
            new ParsedPage('https://example.com/edge', 200, mainText: self::text(150)),
        ]));
    }

    public function testNonIndexableOrEmptyPagesAreIgnored(): void
    {
        $body = self::text(30);

        $this->assertSame([], $this->checkPages([
            new ParsedPage('https://example.com/hub', 200, robotsMeta: 'noindex', mainText: $body . ' extra words'),
            new ParsedPage('https://example.com/item', 200, mainText: $body),
            new ParsedPage('https://example.com/empty', 200),
            new ParsedPage('https://example.com/gone', 404, mainText: $body . ' more'),
        ]));
    }

    public function testEqualLengthPagesAreNotTreatedAsHubs(): void
    {
        $body = self::text(30);

        $this->assertSame([], $this->checkPages([
            new ParsedPage('https://example.com/one', 200, mainText: $body),
            new ParsedPage('https://example.com/two', 200, mainText: $body),
        ]));
    }
}
