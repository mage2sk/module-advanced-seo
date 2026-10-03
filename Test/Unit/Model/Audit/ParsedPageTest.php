<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Panth\AdvancedSEO\Model\Audit\ParsedPage;
use PHPUnit\Framework\TestCase;

class ParsedPageTest extends TestCase
{
    public function testHeadersAndContentType(): void
    {
        $html = new ParsedPage('https://example.com/', 200, headers: ['content-type' => 'text/html; charset=UTF-8']);
        $json = new ParsedPage('https://example.com/a.json', 200, headers: ['content-type' => 'application/json']);
        $none = new ParsedPage('https://example.com/b', 200);

        $this->assertSame('text/html; charset=UTF-8', $html->header('Content-Type'));
        $this->assertSame('', $html->header('x-missing'));
        $this->assertTrue($html->isHtml());
        $this->assertFalse($json->isHtml());
        $this->assertTrue($none->isHtml());
    }

    public function testStatusClassification(): void
    {
        $this->assertTrue((new ParsedPage('u', 204))->isOk());
        $this->assertFalse((new ParsedPage('u', 301))->isOk());
        $this->assertFalse((new ParsedPage('u', 199))->isOk());
    }

    public function testNoindexFromMetaOrHeader(): void
    {
        $this->assertTrue((new ParsedPage('u', 200, robotsMeta: 'NOINDEX, follow'))->isNoindex());
        $this->assertTrue((new ParsedPage('u', 200, xRobotsTag: 'noindex'))->isNoindex());
        $this->assertFalse((new ParsedPage('u', 200, robotsMeta: 'index,follow'))->isNoindex());
    }

    public function testIndexability(): void
    {
        $url = 'https://example.com/shoes';

        $this->assertTrue((new ParsedPage($url, 200))->isIndexable());
        $this->assertTrue((new ParsedPage($url, 200, canonical: 'HTTPS://EXAMPLE.COM/shoes#x'))->isIndexable());
        $this->assertFalse((new ParsedPage($url, 200, canonical: 'https://example.com/other'))->isIndexable());
        $this->assertFalse((new ParsedPage($url, 404))->isIndexable());
        $this->assertFalse((new ParsedPage($url, 200, robotsMeta: 'noindex'))->isIndexable());
        $this->assertFalse((new ParsedPage($url, 200, headers: ['content-type' => 'image/png']))->isIndexable());
    }

    public function testWithTimingReturnsACopy(): void
    {
        $page = new ParsedPage('https://example.com/', 200, ttfbMs: 5.0, totalMs: 9.0, title: 'Home', h1: ['Welcome']);

        $copy = $page->withTiming(120.5, 300.25);

        $this->assertNotSame($page, $copy);
        $this->assertSame(120.5, $copy->ttfbMs);
        $this->assertSame(300.25, $copy->totalMs);
        $this->assertTrue($copy->timingCacheBusted);
        $this->assertSame('Home', $copy->title);
        $this->assertSame(['Welcome'], $copy->h1);
        $this->assertFalse($page->timingCacheBusted);
        $this->assertSame(5.0, $page->ttfbMs);
    }
}
