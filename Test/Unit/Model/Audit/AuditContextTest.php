<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\EntityLookupInterface;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;
use Panth\AdvancedSEO\Model\Audit\ProbeResult;
use PHPUnit\Framework\TestCase;

class AuditContextTest extends TestCase
{
    private function lookup(array|\Throwable|null $entity): EntityLookupInterface
    {
        $lookup = $this->createStub(EntityLookupInterface::class);
        if ($entity instanceof \Throwable) {
            $lookup->method('entityForUrl')->willThrowException($entity);
        } else {
            $lookup->method('entityForUrl')->willReturn($entity);
        }

        return $lookup;
    }

    public function testHostAndPageLookupUseNormalizedUrls(): void
    {
        $page = new ParsedPage('https://example.com/a', 200);
        $ctx  = new AuditContext(baseUrl: 'https://Example.com/', pages: ['https://example.com/a' => $page]);

        $this->assertSame('example.com', $ctx->host());
        $this->assertSame($page, $ctx->page('https://example.com/a'));
        $this->assertSame($page, $ctx->page('HTTPS://EXAMPLE.COM/a#top'));
        $this->assertNull($ctx->page('https://example.com/b'));
    }

    public function testStatusComesFromCrawlThenProbes(): void
    {
        $probe = new ProbeResult('https://cdn.example.com/x.png', 404);
        $ctx   = new AuditContext(
            statuses: ['HTTPS://Example.com/a' => '301'],
            probes: ['https://cdn.example.com/x.png' => $probe]
        );

        $this->assertSame(301, $ctx->statusOf('https://example.com/a#frag'));
        $this->assertSame(404, $ctx->statusOf('https://cdn.example.com/x.png'));
        $this->assertSame($probe, $ctx->probe('https://CDN.example.com/x.png'));
        $this->assertNull($ctx->statusOf('https://example.com/unknown'));
    }

    public function testFinalUrlFollowsTheRedirectMapUpToTwoHops(): void
    {
        $ctx = new AuditContext(redirectMap: [
            'https://example.com/a' => ['https://example.com/b'],
            'https://example.com/b' => ['https://example.com/c'],
            'https://example.com/x' => ['https://example.com/y'],
        ]);

        $this->assertSame('https://example.com/c', $ctx->finalUrlOf('https://example.com/a'));
        $this->assertSame('https://example.com/y', $ctx->finalUrlOf('https://EXAMPLE.com/x'));
    }

    public function testFinalUrlFallsBackToProbeLocation(): void
    {
        $ctx = new AuditContext(probes: [
            'https://example.com/old'  => new ProbeResult('https://example.com/old', 301, headers: ['location' => '/new']),
            'https://example.com/same' => new ProbeResult('https://example.com/same', 200),
        ]);

        $this->assertSame('https://example.com/new', $ctx->finalUrlOf('https://example.com/old'));
        $this->assertSame('', $ctx->finalUrlOf('https://example.com/same'));
        $this->assertSame('', $ctx->finalUrlOf('https://example.com/none'));
    }

    public function testUnresolvableProbeLocationIsReturnedRaw(): void
    {
        $ctx = new AuditContext(probes: [
            'https://example.com/m' => new ProbeResult('https://example.com/m', 302, headers: ['location' => 'mailto:x@y.z']),
        ]);

        $this->assertSame('mailto:x@y.z', $ctx->finalUrlOf('https://example.com/m'));
    }

    public function testEntityLabel(): void
    {
        $url = 'https://example.com/about';

        $this->assertSame('', (new AuditContext())->entityLabel($url));
        $this->assertSame('', (new AuditContext(entityLookup: $this->lookup(new \RuntimeException('db'))))->entityLabel($url));
        $this->assertSame('', (new AuditContext(entityLookup: $this->lookup(null)))->entityLabel($url));
        $this->assertSame(
            'cms #3 "About us"',
            (new AuditContext(entityLookup: $this->lookup(['type' => 'cms', 'id' => 3, 'label' => 'About us'])))->entityLabel($url)
        );
        $this->assertSame(
            'product #4',
            (new AuditContext(entityLookup: $this->lookup(['type' => 'product', 'id' => 4, 'label' => ''])))->entityLabel($url)
        );
    }

    public function testDefaults(): void
    {
        $ctx = new AuditContext();

        $this->assertSame(3000.0, $ctx->slowPageMs);
        $this->assertSame(307200, $ctx->largeHtmlBytes);
        $this->assertTrue($ctx->minifyJs);
        $this->assertNull($ctx->robots);
        $this->assertSame('', $ctx->host());
    }
}
