<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\Check\PageCheckInterface;
use Panth\AdvancedSEO\Model\Audit\Check\SiteCheckInterface;
use Panth\AdvancedSEO\Model\Audit\CrawlResult;
use Panth\AdvancedSEO\Model\Audit\Issue;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\IssueDetector;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;
use Panth\AdvancedSEO\Model\Audit\ResourceProbe;
use Panth\AdvancedSEO\Model\Audit\SiteAuditor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SiteAuditorTest extends TestCase
{
    private function auditor(array $checks, ?LoggerInterface $logger = null): SiteAuditor
    {
        return new SiteAuditor(
            new ResourceProbe(),
            $this->createStub(Config::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(StoreManagerInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class),
            null,
            null,
            $checks
        );
    }

    public function testChecksRegisteredThroughTheArgumentRunPerPageAndPerSiteDedupedAndSorted(): void
    {
        $catalog = new IssueCatalog();
        $page = new class ($catalog) implements PageCheckInterface {
            public function __construct(private readonly IssueCatalog $catalog)
            {
            }

            public function check(ParsedPage $page, AuditContext $ctx): array
            {
                return [
                    $this->catalog->create('empty_anchor_text', $page->url, '/x', 'No text'),
                    $this->catalog->create('empty_anchor_text', $page->url, '/x', 'Duplicate'),
                ];
            }
        };
        $site = new class ($catalog) implements SiteCheckInterface {
            public function __construct(private readonly IssueCatalog $catalog)
            {
            }

            public function checkSite(array $pages, AuditContext $ctx): array
            {
                return [$this->catalog->create('duplicate_title', 'https://example.com/a', 'T', 'Shared')];
            }
        };

        $pages = [
            'https://example.com/a' => new ParsedPage('https://example.com/a', 200),
            'https://example.com/b' => new ParsedPage('https://example.com/b', 200),
        ];
        $auditor = $this->auditor(['page' => $page, 'site' => $site, 'not_a_check' => new \stdClass()]);
        $issues  = $auditor->runChecks($pages, new AuditContext(1, 'https://example.com/'));

        $this->assertCount(2, $auditor->getChecks());
        $this->assertCount(3, $issues);
        $this->assertSame(Issue::SEVERITY_ERROR, $issues[0]->severity);
        $this->assertSame('duplicate_title', $issues[0]->code);
    }

    public function testAFailingCheckIsLoggedAndDoesNotStopTheOthers(): void
    {
        $failing = new class implements PageCheckInterface {
            public function check(ParsedPage $page, AuditContext $ctx): array
            {
                throw new \RuntimeException('boom');
            }
        };
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $issues = $this->auditor(['failing' => $failing], $logger)->runChecks(
            ['https://example.com/a' => new ParsedPage('https://example.com/a', 200)],
            new AuditContext(1, 'https://example.com/')
        );

        $this->assertSame([], $issues);
    }

    public function testIssueDetectorKeepsLegacySummaryAndAddsStructuredIssues(): void
    {
        $results = [
            new CrawlResult('https://example.com/a', 200, "Same  Title", 'D', 'https://example.com/a'),
            new CrawlResult('https://example.com/b', 200, 'same title', 'D', 'https://example.com/b'),
            new CrawlResult('https://example.com/c', 404),
        ];

        $analysis = (new IssueDetector())->analyse($results);

        $this->assertSame(2, $analysis['summary']['duplicate_titles']);
        $this->assertSame(1, $analysis['summary']['status_404']);
        $this->assertSame(
            [
                'missing_title', 'missing_description', 'title_too_long', 'description_too_long', 'missing_canonical',
                'status_404', 'status_5xx', 'duplicate_titles', 'fetch_errors', 'redirects', 'redirect_chains', 'noindex_pages',
            ],
            array_keys($analysis['summary'])
        );
        $this->assertSame(['status_404'], array_map(static fn (Issue $i): string => $i->code, $analysis['issues']));
        $this->assertSame(['404 status'], $analysis['results'][2]->issues);
    }
}
