<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Panth\AdvancedSEO\Model\Audit\CrawlResult;
use Panth\AdvancedSEO\Model\Audit\IssueDetector;
use PHPUnit\Framework\TestCase;

class IssueDetectorTest extends TestCase
{
    private function issuesFor(array $results, array $redirectMap = []): array
    {
        $analysis = (new IssueDetector())->analyse($results, $redirectMap);
        $out = [];
        foreach ($analysis['results'] as $r) {
            $out[$r->url] = $r->issues;
        }

        return [$out, $analysis['summary']];
    }

    public function testARedirectIsReportedAsARedirectNotAsAHealthyPage(): void
    {
        [$issues, $summary] = $this->issuesFor(
            [new CrawlResult(url: 'https://example.com/old', statusCode: 301)],
            ['https://example.com/old' => ['https://example.com/new']]
        );

        $this->assertSame(1, $summary['redirects']);
        $this->assertStringContainsString('Redirects (301) to https://example.com/new', $issues['https://example.com/old'][0]);
    }

    public function testARedirectDoesNotCollectContentIssues(): void
    {
        [$issues] = $this->issuesFor([new CrawlResult(url: 'https://example.com/old', statusCode: 302)]);

        $joined = implode(' ', $issues['https://example.com/old']);
        $this->assertStringNotContainsString('Missing title', $joined);
        $this->assertStringNotContainsString('No canonical', $joined);
    }

    public function testALongRedirectChainIsFlagged(): void
    {
        [$issues, $summary] = $this->issuesFor(
            [new CrawlResult(url: 'https://example.com/a', statusCode: 301)],
            ['https://example.com/a' => ['https://example.com/b', 'https://example.com/c', 'https://example.com/d']]
        );

        $this->assertSame(1, $summary['redirect_chains']);
        $this->assertStringContainsString('Redirect chain too long', implode(' ', $issues['https://example.com/a']));
    }

    public function testAShortRedirectChainIsNotFlagged(): void
    {
        [, $summary] = $this->issuesFor(
            [new CrawlResult(url: 'https://example.com/a', statusCode: 301)],
            ['https://example.com/a' => ['https://example.com/b']]
        );

        $this->assertSame(0, $summary['redirect_chains']);
    }

    public function testContentIssuesAreStillDetectedOnHealthyPages(): void
    {
        [$issues, $summary] = $this->issuesFor([
            new CrawlResult(url: 'https://example.com/p', statusCode: 200, title: '', description: '', canonical: ''),
        ]);

        $joined = implode(' ', $issues['https://example.com/p']);
        $this->assertStringContainsString('Missing title', $joined);
        $this->assertStringContainsString('Missing description', $joined);
        $this->assertStringContainsString('No canonical', $joined);
        $this->assertSame(1, $summary['missing_title']);
    }

    public function testDuplicateTitlesAreReportedAcrossPages(): void
    {
        [$issues, $summary] = $this->issuesFor([
            new CrawlResult(url: 'https://example.com/a', statusCode: 200, title: 'Same Title', description: 'd', canonical: 'c'),
            new CrawlResult(url: 'https://example.com/b', statusCode: 200, title: 'Same Title', description: 'd', canonical: 'c'),
        ]);

        $this->assertSame(2, $summary['duplicate_titles']);
        $this->assertStringContainsString('Duplicate title', implode(' ', $issues['https://example.com/a']));
    }

    public function testFetchFailuresAndServerErrorsAreCounted(): void
    {
        [, $summary] = $this->issuesFor([
            new CrawlResult(url: 'https://example.com/x', statusCode: 0),
            new CrawlResult(url: 'https://example.com/y', statusCode: 404),
            new CrawlResult(url: 'https://example.com/z', statusCode: 503),
        ]);

        $this->assertSame(1, $summary['fetch_errors']);
        $this->assertSame(1, $summary['status_404']);
        $this->assertSame(1, $summary['status_5xx']);
    }
}
