<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\SingleIncomingLinkCheck;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class SingleIncomingLinkCheckTest extends AbstractCheckTestCase
{
    private function pages(): array
    {
        $hub    = $this->page('single_incoming_link.positive.html', 'https://example.com/');
        $second = $this->page('single_incoming_link.negative.html', 'https://example.com/second-hub');
        $a      = new ParsedPage('https://example.com/service-a', 200, title: 'A');
        $b      = new ParsedPage('https://example.com/service-b', 200, title: 'B');

        return [$hub->url => $hub, $second->url => $second, $a->url => $a, $b->url => $b];
    }

    public function testPageWithOneIncomingLinkAndOrphanSitemapUrlAreReported(): void
    {
        $issues = (new SingleIncomingLinkCheck($this->catalog()))->checkSite($this->pages(), $this->ctx([
            'crawlComplete' => true,
            'sitemapUrls'   => ['https://example.com/service-b', 'https://example.com/contact-us'],
            'sitemapSampled' => true,
            'statuses'      => ['https://example.com/service-a/' => 200],
        ]));

        $single = array_values(array_filter($issues, static fn ($i) => $i->code === SingleIncomingLinkCheck::CODE));
        $this->assertSame(['https://example.com/service-b'], array_map(static fn ($i) => $i->url, $single));

        $orphans = array_values(array_filter($issues, static fn ($i) => $i->code === SingleIncomingLinkCheck::CODE_ORPHAN));
        $this->assertSame(['https://example.com/contact-us'], array_map(static fn ($i) => $i->url, $orphans));
        $this->assertStringContainsString('sampled', $orphans[0]->detail);

        $this->assertContains(SingleIncomingLinkCheck::CODE_TRAILING_TWIN, $this->codes($issues));
    }

    public function testWellLinkedPagesAndIncompleteCrawlsDoNotReport(): void
    {
        $pages = $this->pages();
        unset($pages['https://example.com/service-b']);
        $pages['https://example.com/second-hub'] = $this->page('single_incoming_link.positive.html', 'https://example.com/second-hub');

        $issues = (new SingleIncomingLinkCheck($this->catalog()))->checkSite($pages, $this->ctx([
            'crawlComplete' => false,
            'sitemapUrls'   => ['https://example.com/contact-us'],
        ]));

        $this->assertSame([], $issues);
    }
}
