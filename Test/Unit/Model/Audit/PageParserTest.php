<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Panth\AdvancedSEO\Model\Audit\PageParser;
use Panth\AdvancedSEO\Model\Audit\UrlHelper;
use PHPUnit\Framework\TestCase;

class PageParserTest extends TestCase
{
    private const HTML = <<<HTML
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<title>  Example   Page </title>
<meta name="Description" content="Short &amp; sweet">
<meta name="robots" content="index,follow">
<link rel="canonical" href="/example-page">
<link rel="alternate" hreflang="en-GB" href="https://example.com/example-page">
<link rel="stylesheet" href="/static/styles.css">
<script src="/static/app.js"></script>
<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"Example"},{"@type":"WebPage","name":"Example"}]}</script>
<style>.a{color:red}</style>
</head><body>
<header><nav><a href="/">Home</a></nav></header>
<main>
<h1>Example <span>Page</span></h1>
<p>Visible text.</p>
<a href="https://other.example.net/x" rel="NoFollow Sponsored">Other</a>
<a href="mailto:hello@example.com">Mail</a>
<a href="{{url}}">Placeholder</a>
<template><a href="/hidden-in-template">Hidden</a></template>
<noscript><a href="/hidden-in-noscript">Hidden</a></noscript>
<a href="../docs/x.png"><img src="https://raw.githubusercontent.com/example-org/example-repo/main/docs/x.png" srcset="/media/a.jpg 1x, /media/b.jpg 2x" data-src="/media/lazy.jpg" alt="X"></a>
</main>
<footer>Footer</footer>
</body></html>
HTML;

    public function testParsesHeadBodyLinksImagesAndJsonLd(): void
    {
        $page = (new PageParser())->parse(
            'https://example.com/section/example-page',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => ['noarchive', 'nosnippet']],
            self::HTML,
            120.0,
            340.0
        );

        $this->assertSame('Example Page', $page->title);
        $this->assertSame('Short & sweet', $page->metaDescription);
        $this->assertSame(['Example Page'], $page->h1);
        $this->assertSame('https://example.com/example-page', $page->canonical);
        $this->assertSame('index,follow', $page->robotsMeta);
        $this->assertSame('noarchive, nosnippet', $page->xRobotsTag);
        $this->assertSame([['locale' => 'en-GB', 'href' => 'https://example.com/example-page']], $page->hreflang);
        $this->assertSame(['https://example.com/static/app.js'], $page->scripts);
        $this->assertSame(['https://example.com/static/styles.css'], $page->styles);
        $this->assertCount(2, $page->jsonLd);
        $this->assertTrue($page->jsonLdBlocks[0]['hasContext']);
        $this->assertSame(1, $page->landmarks['main']);
        $this->assertSame(1, $page->landmarks['nav']);
        $this->assertGreaterThan(0, $page->inlineBytes['style']);
        $this->assertSame(120.0, $page->ttfbMs);
        $this->assertSame(340.0, $page->totalMs);

        $hrefs = array_column($page->links, 'href');
        $this->assertContains('https://example.com/', $hrefs);
        $this->assertContains('https://other.example.net/x', $hrefs);
        $this->assertContains('https://example.com/docs/x.png', $hrefs);
        $this->assertNotContains('https://example.com/hidden-in-template', $hrefs);
        $this->assertNotContains('https://example.com/hidden-in-noscript', $hrefs);
        $this->assertCount(3, $hrefs);

        $external = $page->links[array_search('https://other.example.net/x', $hrefs, true)];
        $this->assertSame('nofollow sponsored', $external['rel']);
        $this->assertFalse($external['isInternal']);

        $image = $page->images[0];
        $this->assertSame(['https://example.com/media/a.jpg', 'https://example.com/media/b.jpg'], $image['srcset']);
        $this->assertSame('https://example.com/media/lazy.jpg', $image['dataSrc']);
        $this->assertSame('../docs/x.png', $image['rawLinkHref']);
        $this->assertSame('https://example.com/docs/x.png', $image['linkHref']);
        $this->assertStringContainsString('Visible text.', $page->mainText);
        $this->assertStringNotContainsString('color:red', $page->mainText);
    }

    public function testNonHtmlResponsesAreNotParsed(): void
    {
        $page = (new PageParser())->parse('https://example.com/file.pdf', 200, ['Content-Type' => 'application/pdf'], '%PDF-1.4');

        $this->assertSame('', $page->title);
        $this->assertSame([], $page->links);
        $this->assertSame(8, $page->htmlBytes);
    }

    public function testUrlResolution(): void
    {
        $this->assertSame('https://example.com/a/docs/x.png', UrlHelper::resolve('docs/x.png', 'https://example.com/a/b.html'));
        $this->assertSame('https://example.com/docs/x.png', UrlHelper::resolve('./../docs/x.png', 'https://example.com/a/b.html'));
        $this->assertSame('https://example.com/a/b.html?x=1', UrlHelper::resolve('?x=1', 'https://example.com/a/b.html'));
        $this->assertNull(UrlHelper::resolve('javascript:void(0)', 'https://example.com/'));
        $this->assertNull(UrlHelper::resolve('#top', 'https://example.com/'));
        $this->assertSame('https://example.com/service-a/', UrlHelper::trailingSlashTwin('https://example.com/service-a'));
        $this->assertNull(UrlHelper::trailingSlashTwin('https://example.com/item.html'));
    }
}
