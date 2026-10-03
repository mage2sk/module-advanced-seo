<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\LinkToDisabledEntityCheck;
use Panth\AdvancedSEO\Model\Audit\Check\MetaCacheStaleCheck;
use Panth\AdvancedSEO\Model\Audit\Check\ThinDuplicatePagesCheck;
use Panth\AdvancedSEO\Model\Audit\EntityLookupInterface;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class ExtraRegressionChecksTest extends AbstractCheckTestCase
{
    private function lookup(): EntityLookupInterface
    {
        return new class implements EntityLookupInterface {
            public function entityForUrl(string $url, int $storeId): ?array
            {
                return match (parse_url($url, PHP_URL_PATH)) {
                    '/service-old' => ['type' => 'cms', 'id' => 7, 'label' => 'Old service', 'active' => false],
                    '/service-a'   => ['type' => 'cms', 'id' => 3, 'label' => 'Service A', 'active' => true],
                    default        => null,
                };
            }

            public function resolvedTitle(string $url, int $storeId): ?string
            {
                return parse_url($url, PHP_URL_PATH) === '/service-a' ? 'New Service A Title' : null;
            }

            public function sourcesLinkingTo(string $url, ?array $entity, int $storeId): array
            {
                return ['panth_megamenu_item #12 (Old service)'];
            }
        };
    }

    public function testThinItemPageContainedInHubIsReported(): void
    {
        $hub  = $this->page('thin_duplicate_pages.hub.html', 'https://example.com/faq/category/shipping');
        $item = $this->page('thin_duplicate_pages.item.html', 'https://example.com/faq/item/delivery-time');

        $issues = (new ThinDuplicatePagesCheck($this->catalog()))->checkSite([$hub->url => $hub, $item->url => $item], $this->ctx());

        $this->assertCount(1, $issues);
        $this->assertSame($item->url, $issues[0]->url);
        $this->assertSame($hub->url, $issues[0]->element);
    }

    public function testDistinctPagesAreNotThinDuplicates(): void
    {
        $hub   = $this->page('thin_duplicate_pages.hub.html', 'https://example.com/faq/category/shipping');
        $other = $this->page('low_text_html_ratio.negative.html', 'https://example.com/article');

        $this->assertSame([], (new ThinDuplicatePagesCheck($this->catalog()))->checkSite([$hub->url => $hub, $other->url => $other], $this->ctx()));
    }

    public function testStaleMetaCacheIsReportedWhenTheTitleDiffersFromResolvedMeta(): void
    {
        $stale = new ParsedPage('https://example.com/service-a', 200, title: 'Old Service A Title');
        $fresh = new ParsedPage('https://example.com/service-a', 200, title: 'New Service A Title | Example Store');
        $check = new MetaCacheStaleCheck($this->catalog());
        $ctx   = $this->ctx(['entityLookup' => $this->lookup()]);

        $this->assertSame([MetaCacheStaleCheck::CODE], $this->codes($check->checkSite([$stale], $ctx)));
        $this->assertSame([], $check->checkSite([$fresh], $ctx));
    }

    public function testLinkToDisabledEntityNamesTheSourceRow(): void
    {
        $page = new ParsedPage('https://example.com/', 200, links: [
            ['href' => 'https://example.com/service-old', 'rawHref' => '/service-old', 'rel' => '', 'anchorText' => 'Old', 'isInternal' => true],
            ['href' => 'https://example.com/service-a', 'rawHref' => '/service-a', 'rel' => '', 'anchorText' => 'A', 'isInternal' => true],
        ]);
        $issues = (new LinkToDisabledEntityCheck($this->catalog()))->checkSite([$page], $this->ctx([
            'entityLookup' => $this->lookup(),
            'statuses'     => ['https://example.com/service-old' => 404, 'https://example.com/service-a' => 200],
        ]));

        $this->assertCount(1, $issues);
        $this->assertSame('https://example.com/service-old', $issues[0]->element);
        $this->assertStringContainsString('panth_megamenu_item #12', $issues[0]->detail);
    }
}
