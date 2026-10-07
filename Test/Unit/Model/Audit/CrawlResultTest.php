<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Panth\AdvancedSEO\Model\Audit\CrawlResult;
use PHPUnit\Framework\TestCase;

class CrawlResultTest extends TestCase
{
    public function testWithIssuesReturnsAMergedCopy(): void
    {
        $result = new CrawlResult('https://example.com/a', 200, 'T', 'D', 'https://example.com/a', 'index', ['one']);

        $copy = $result->withIssues(['two', 'three']);

        $this->assertNotSame($result, $copy);
        $this->assertSame(['one'], $result->issues);
        $this->assertSame(['one', 'two', 'three'], $copy->issues);
        $this->assertSame('T', $copy->title);
        $this->assertSame('index', $copy->robots);
        $this->assertSame(200, $copy->statusCode);
    }

    public function testToArrayMapsColumnsAndEncodesIssues(): void
    {
        $result = new CrawlResult('https://example.com/caf/', 404, 'Caf', '', '', 'noindex', ['Missing / title', "Caf\u{00e9}"]);

        $this->assertSame([
            'url'              => 'https://example.com/caf/',
            'status_code'      => 404,
            'meta_title'       => 'Caf',
            'meta_description' => '',
            'canonical'        => '',
            'robots'           => 'noindex',
            'issues_json'      => "[\"Missing / title\",\"Caf\u{00e9}\"]",
        ], $result->toArray());
    }
}
