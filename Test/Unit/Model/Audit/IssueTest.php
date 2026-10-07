<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Panth\AdvancedSEO\Model\Audit\Issue;
use PHPUnit\Framework\TestCase;

class IssueTest extends TestCase
{
    public function testValidIssueExposesKeyAndArray(): void
    {
        $issue = new Issue(
            'missing_title',
            Issue::SEVERITY_ERROR,
            Issue::CATEGORY_META,
            'https://example.com/a',
            '<title>',
            'No title',
            'Add one'
        );

        $this->assertSame('missing_title|https://example.com/a|<title>', $issue->key());
        $this->assertSame([
            'code'     => 'missing_title',
            'severity' => 'error',
            'category' => 'meta',
            'url'      => 'https://example.com/a',
            'element'  => '<title>',
            'detail'   => 'No title',
            'fix_hint' => 'Add one',
        ], $issue->toArray());
    }

    public function testSeverityRank(): void
    {
        $this->assertSame(3, Issue::severityRank(Issue::SEVERITY_ERROR));
        $this->assertSame(2, Issue::severityRank(Issue::SEVERITY_WARNING));
        $this->assertSame(1, Issue::severityRank(Issue::SEVERITY_NOTICE));
        $this->assertSame(0, Issue::severityRank('fatal'));
    }

    public function testUnknownSeverityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown audit issue severity "fatal".');
        new Issue('code', 'fatal', Issue::CATEGORY_META, 'u');
    }

    public function testUnknownCategoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown audit issue category "seo".');
        new Issue('code', Issue::SEVERITY_NOTICE, 'seo', 'u');
    }

    public function testNonSnakeCaseCodeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Audit issue code "MissingTitle" must be snake_case.');
        new Issue('MissingTitle', Issue::SEVERITY_NOTICE, Issue::CATEGORY_META, 'u');
    }
}
