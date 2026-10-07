<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Panth\AdvancedSEO\Model\Audit\UrlHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlHelperTest extends TestCase
{
    public static function httpProvider(): array
    {
        return [
            'empty'            => ['', false],
            'fragment'         => ['#top', false],
            'protocol relative' => ['//cdn.example.com/a.js', true],
            'absolute path'    => ['/shoes', true],
            'relative'         => ['page.html', true],
            'uppercase https'  => ['HTTPS://example.com', true],
            'mailto'           => ['mailto:a@example.com', false],
            'javascript'       => ['javascript:void(0)', false],
            'tel'              => [' tel:123', false],
        ];
    }

    #[DataProvider('httpProvider')]
    public function testIsHttp(string $href, bool $expected): void
    {
        $this->assertSame($expected, UrlHelper::isHttp($href));
    }

    public function testTemplatePlaceholdersAreDetected(): void
    {
        foreach (['{{url}}', '/a/${id}', '<%= x %>', '#{slug}', '[[link]]', 'x}}'] as $href) {
            $this->assertTrue(UrlHelper::isTemplatePlaceholder($href), $href);
        }
        $this->assertFalse(UrlHelper::isTemplatePlaceholder('/plain/path?x=1'));
    }

    public function testIsRelative(): void
    {
        $this->assertTrue(UrlHelper::isRelative('docs/x.png'));
        $this->assertTrue(UrlHelper::isRelative('../x'));
        foreach (['', '/abs', '#frag', '?q=1', 'https://example.com', 'mailto:x'] as $href) {
            $this->assertFalse(UrlHelper::isRelative($href), $href);
        }
    }

    public static function resolveProvider(): array
    {
        return [
            'absolute keeps itself minus fragment' => ['https://other.com/x#frag', 'https://example.com/a', 'https://other.com/x'],
            'protocol relative takes base scheme'  => ['//cdn.com/a.js', 'http://example.com/', 'http://cdn.com/a.js'],
            'query only'                           => ['?page=2', 'https://example.com/cat/shoes', 'https://example.com/cat/shoes?page=2'],
            'relative with port and query'         => ['img/a.png?v=1', 'https://example.com:8080/cat/shoes', 'https://example.com:8080/cat/img/a.png?v=1'],
            'dot segments'                         => ['../up', 'https://example.com/a/b/c', 'https://example.com/a/up'],
            'root relative'                        => ['/abs#x', 'https://example.com/a/b', 'https://example.com/abs'],
            'entities are decoded'                 => ['/a?x=1&amp;y=2', 'https://example.com/', 'https://example.com/a?x=1&y=2'],
            'base without path'                    => ['x.html', 'https://example.com', 'https://example.com/x.html'],
        ];
    }

    #[DataProvider('resolveProvider')]
    public function testResolve(string $href, string $base, string $expected): void
    {
        $this->assertSame($expected, UrlHelper::resolve($href, $base));
    }

    public function testResolveRejectsNonHttpPlaceholdersAndBadBases(): void
    {
        $this->assertNull(UrlHelper::resolve('mailto:x@example.com', 'https://example.com/'));
        $this->assertNull(UrlHelper::resolve('/p/{{id}}', 'https://example.com/'));
        $this->assertNull(UrlHelper::resolve('/p', 'not a url'));
        $this->assertNull(UrlHelper::resolve('#only', 'https://example.com/'));
    }

    public static function dotSegmentProvider(): array
    {
        return [
            ['/a/./b/../c', '/a/c'],
            ['/a/b/.', '/a/b/'],
            ['/a/b/..', '/a/'],
            ['/..', '/'],
            ['a/b', '/a/b'],
            ['/a/../../b', '/b'],
        ];
    }

    #[DataProvider('dotSegmentProvider')]
    public function testRemoveDotSegments(string $path, string $expected): void
    {
        $this->assertSame($expected, UrlHelper::removeDotSegments($path));
    }

    public function testUrlParts(): void
    {
        $this->assertSame('https://example.com/a?b', UrlHelper::stripFragment('https://example.com/a?b#c'));
        $this->assertSame('https://example.com/a', UrlHelper::stripFragment('https://example.com/a'));
        $this->assertSame('example.com', UrlHelper::host('https://EXAMPLE.com/x'));
        $this->assertSame('', UrlHelper::host('/relative'));
        $this->assertSame('/', UrlHelper::path('https://example.com'));
        $this->assertSame('/a/b', UrlHelper::path('https://example.com/a/b?x=1'));
        $this->assertSame('x=1&y=2', UrlHelper::query('https://example.com/?x=1&y=2'));
        $this->assertSame('', UrlHelper::query('https://example.com/'));
    }

    public function testExtensionAndResourceClassification(): void
    {
        $this->assertSame('png', UrlHelper::extension('https://example.com/img/A.PNG?v=2'));
        $this->assertSame('', UrlHelper::extension('https://example.com/v1.2/page'));
        $this->assertSame('', UrlHelper::extension('https://example.com/page'));
        $this->assertTrue(UrlHelper::isResource('https://example.com/styles.css'));
        $this->assertFalse(UrlHelper::isImage('https://example.com/styles.css'));
        $this->assertTrue(UrlHelper::isImage('https://example.com/a.webp'));
        $this->assertFalse(UrlHelper::isResource('https://example.com/page.html'));
    }

    public function testSameHost(): void
    {
        $this->assertTrue(UrlHelper::sameHost('https://Example.com/a', 'example.COM'));
        $this->assertFalse(UrlHelper::sameHost('https://cdn.example.com/a', 'example.com'));
        $this->assertFalse(UrlHelper::sameHost('/relative', ''));
    }

    public function testNormalize(): void
    {
        $this->assertSame('https://example.com:8080/Path?q=1', UrlHelper::normalize(' HTTPS://Example.COM:8080/Path?q=1#frag '));
        $this->assertSame('https://example.com/', UrlHelper::normalize('https://example.com'));
        $this->assertSame('https://example.com/a', UrlHelper::normalize('https://example.com/a?'));
        $this->assertSame('relative/path', UrlHelper::normalize('relative/path#x'));
    }

    public function testTrailingSlashTwin(): void
    {
        $this->assertSame('https://example.com/shoes/', UrlHelper::trailingSlashTwin('https://example.com/shoes'));
        $this->assertSame('https://example.com:81/shoes?a=1', UrlHelper::trailingSlashTwin('https://example.com:81/shoes/?a=1'));
        $this->assertNull(UrlHelper::trailingSlashTwin('https://example.com/'));
        $this->assertNull(UrlHelper::trailingSlashTwin('https://example.com/page.html'));
        $this->assertNull(UrlHelper::trailingSlashTwin('no-host'));
    }
}
