<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\MetaCacheStaleCheck;
use Panth\AdvancedSEO\Model\Audit\EntityLookupInterface;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class MetaCacheStaleCheckTest extends AbstractCheckTestCase
{
    private function lookup(array $titles): EntityLookupInterface
    {
        $lookup = $this->createStub(EntityLookupInterface::class);
        $lookup->method('resolvedTitle')->willReturnCallback(static function (string $url) use ($titles): ?string {
            $title = $titles[$url] ?? null;
            if ($title instanceof \Throwable) {
                throw $title;
            }

            return $title;
        });

        return $lookup;
    }

    public function testWithoutLookupNothingIsChecked(): void
    {
        $page = new ParsedPage('https://example.com/a', 200, title: 'Old');

        $this->assertSame([], (new MetaCacheStaleCheck($this->catalog()))->checkSite([$page], $this->ctx()));
    }

    public function testSkippedPagesAndLookupResults(): void
    {
        $ctx = $this->ctx(['entityLookup' => $this->lookup([
            'https://example.com/q?x=1'  => 'Fresh',
            'https://example.com/noidx'  => 'Fresh',
            'https://example.com/untitled' => 'Fresh',
            'https://example.com/boom'   => new \RuntimeException('db'),
            'https://example.com/blank'  => '  ',
        ])]);
        $pages = [
            new ParsedPage('https://example.com/q?x=1', 200, title: 'Old'),
            new ParsedPage('https://example.com/noidx', 200, title: 'Old', robotsMeta: 'noindex'),
            new ParsedPage('https://example.com/untitled', 200),
            new ParsedPage('https://example.com/boom', 200, title: 'Old'),
            new ParsedPage('https://example.com/blank', 200, title: 'Old'),
            new ParsedPage('https://example.com/unmapped', 200, title: 'Old'),
            'not a page',
        ];

        $this->assertSame([], (new MetaCacheStaleCheck($this->catalog()))->checkSite($pages, $ctx));
    }

    public function testEntityEncodedResolvedTitleMatchesTheRenderedTitle(): void
    {
        $ctx  = $this->ctx(['entityLookup' => $this->lookup(['https://example.com/a' => 'Tea &amp; Cake'])]);
        $page = new ParsedPage('https://example.com/a', 200, title: 'Tea & Cake | Example');

        $this->assertSame([], (new MetaCacheStaleCheck($this->catalog()))->checkSite([$page], $ctx));
    }

    public function testDifferenceIsReportedWithBothTitles(): void
    {
        $ctx  = $this->ctx(['entityLookup' => $this->lookup(['https://example.com/a' => 'New title'])]);
        $page = new ParsedPage('https://example.com/a', 200, title: 'Old title');

        $issues = (new MetaCacheStaleCheck($this->catalog()))->checkSite([$page], $ctx);

        $this->assertCount(1, $issues);
        $this->assertSame('<title>', $issues[0]->element);
        $this->assertSame(
            'Rendered title "Old title" differs from the resolved meta title "New title".',
            $issues[0]->detail
        );
    }
}
