<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\LinkToDisabledEntityCheck;
use Panth\AdvancedSEO\Model\Audit\EntityLookupInterface;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class LinkToDisabledEntityCheckTest extends AbstractCheckTestCase
{
    private static function link(string $path, bool $internal = true): array
    {
        return [
            'href'       => 'https://example.com' . $path,
            'rawHref'    => $path,
            'rel'        => '',
            'anchorText' => 'x',
            'isInternal' => $internal,
        ];
    }

    private function lookup(array $entities, array $sources = []): EntityLookupInterface
    {
        $lookup = $this->createStub(EntityLookupInterface::class);
        $lookup->method('entityForUrl')->willReturnCallback(static function (string $url) use ($entities): ?array {
            $entity = $entities[parse_url($url, PHP_URL_PATH)] ?? null;
            if ($entity instanceof \Throwable) {
                throw $entity;
            }

            return $entity;
        });
        $lookup->method('sourcesLinkingTo')->willReturn($sources);

        return $lookup;
    }

    public function testWithoutLookupNothingIsReported(): void
    {
        $page = new ParsedPage('https://example.com/', 200, links: [self::link('/gone')]);

        $this->assertSame([], (new LinkToDisabledEntityCheck($this->catalog()))->checkSite(
            [$page],
            $this->ctx(['statuses' => ['https://example.com/gone' => 404]])
        ));
    }

    public function testEveryLinkingPageIsReportedForAGoneEntity(): void
    {
        $pages = [
            new ParsedPage('https://example.com/', 200, links: [self::link('/gone'), self::link('/gone', false)]),
            new ParsedPage('https://example.com/b', 200, links: [self::link('/gone')]),
            new ParsedPage('https://example.com/err', 500, links: [self::link('/gone')]),
        ];
        $ctx = $this->ctx([
            'statuses'     => ['https://example.com/gone' => 410],
            'entityLookup' => $this->lookup(['/gone' => ['type' => 'product', 'id' => 9, 'active' => false]]),
        ]);

        $issues = (new LinkToDisabledEntityCheck($this->catalog()))->checkSite($pages, $ctx);

        $this->assertSame(['https://example.com/', 'https://example.com/b'], array_map(static fn ($i) => $i->url, $issues));
        $this->assertSame('Links to disabled product #9 (HTTP 410) from 2 page(s).', $issues[0]->detail);
    }

    public function testSourcesAreLimitedToFive(): void
    {
        $page = new ParsedPage('https://example.com/', 200, links: [self::link('/tmp')]);
        $ctx  = $this->ctx([
            'statuses'     => ['https://example.com/tmp' => 302],
            'entityLookup' => $this->lookup(
                ['/tmp' => ['type' => 'cms', 'id' => 2, 'active' => false]],
                ['s1', 's2', 's3', 's4', 's5', 's6']
            ),
        ]);

        $issues = (new LinkToDisabledEntityCheck($this->catalog()))->checkSite([$page], $ctx);

        $this->assertStringEndsWith(' Sources: s1; s2; s3; s4; s5.', $issues[0]->detail);
    }

    public function testActiveUnknownOrFailingEntitiesAndHealthyLinksAreSkipped(): void
    {
        $page = new ParsedPage('https://example.com/', 200, links: [
            self::link('/active'),
            self::link('/unknown'),
            self::link('/boom'),
            self::link('/ok'),
        ]);
        $ctx = $this->ctx([
            'statuses'     => [
                'https://example.com/active'  => 404,
                'https://example.com/unknown' => 404,
                'https://example.com/boom'    => 404,
                'https://example.com/ok'      => 200,
            ],
            'entityLookup' => $this->lookup([
                '/active' => ['type' => 'cms', 'id' => 1, 'active' => true],
                '/boom'   => new \RuntimeException('db'),
                '/ok'     => ['type' => 'cms', 'id' => 5, 'active' => false],
            ]),
        ]);

        $this->assertSame([], (new LinkToDisabledEntityCheck($this->catalog()))->checkSite([$page, 'junk'], $ctx));
    }
}
