<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

use Panth\AdvancedSEO\Helper\Config;
use Psr\Log\LoggerInterface;

class CrawlRunner
{
    public const SYNC_PAGE_LIMIT = 25;

    private const PROGRESS_EVERY_PAGES = 3;

    public function __construct(
        private readonly Crawler $crawler,
        private readonly IssueDetector $issueDetector,
        private readonly ResultPersister $persister,
        private readonly CrawlState $crawlState,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ?SiteAuditor $siteAuditor = null,
        private readonly ?IssuePersister $issuePersister = null,
        private readonly ?PageParser $pageParser = null
    ) {
    }

    public function run(int $storeId, ?int $maxPages = null, bool $persist = true): array
    {
        $maxPages = $maxPages !== null ? max(1, $maxPages) : $this->config->getCrawlDepth($storeId);

        $this->crawlState->markRunning($storeId, $maxPages);
        $this->logger->info(sprintf(
            'Panth SEO crawl: store %d started (max %d pages).',
            $storeId,
            $maxPages
        ));

        $cancelled = false;
        $pages     = [];
        $onPage    = null;
        if ($this->siteAuditor !== null && $this->pageParser !== null) {
            $onPage = function (CrawlResult $result, string $body, array $headers, float $elapsedMs) use (&$pages): void {
                $pages[$result->url] = $this->pageParser->parse(
                    $result->url,
                    $result->statusCode,
                    $headers,
                    $body,
                    $elapsedMs,
                    $elapsedMs
                );
            };
        }

        try {
            $rawResults = $this->crawler->crawl(
                $storeId,
                $maxPages,
                function (int $crawled, int $queued) use ($storeId, &$cancelled): bool {
                    if ($crawled % self::PROGRESS_EVERY_PAGES !== 0) {
                        return true;
                    }

                    $state = $this->crawlState->reportProgress($storeId, $crawled, $queued);

                    if ($state['cancel_requested']) {
                        $cancelled = true;
                        return false;
                    }

                    return true;
                },
                $onPage
            );

            $redirectMap = $this->crawler->getRedirectMap();
            $analysis    = $this->issueDetector->analyse($rawResults, $redirectMap);

            $results     = $analysis['results'];
            $summary     = $analysis['summary'];
            $issues      = (int) array_sum($summary);
            $auditIssues = $analysis['issues'] ?? [];

            if ($this->siteAuditor !== null && !$cancelled && $rawResults !== []) {
                try {
                    $siteIssues  = $this->siteAuditor->audit(
                        $storeId,
                        $pages,
                        $rawResults,
                        $redirectMap,
                        $this->crawler->wasLastCrawlComplete()
                    );
                    $results     = $this->attachDetails($results, $siteIssues);
                    $auditIssues = SiteAuditor::sort(array_merge($auditIssues, $siteIssues));
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf(
                        'Panth SEO crawl: site audit checks for store %d failed: %s',
                        $storeId,
                        $e->getMessage()
                    ));
                }
            }

            $severityCounts = self::countBySeverity($auditIssues);
            $saved          = $persist ? $this->persister->persist($storeId, $results) : 0;
            $runId          = 0;
            if ($persist && $this->issuePersister !== null) {
                try {
                    $runId = $this->issuePersister->persist(
                        $storeId,
                        $auditIssues,
                        $this->config->getAuditHistoryRuns($storeId)
                    );
                } catch (\Throwable $e) {
                    $this->logger->error('Panth SEO crawl: saving audit issues failed: ' . $e->getMessage());
                }
            }

            if ($cancelled) {
                $this->crawlState->markCancelled($storeId, count($results), $saved);
                $this->logger->info(sprintf(
                    'Panth SEO crawl: store %d cancelled after %d page(s).',
                    $storeId,
                    count($results)
                ));
            } else {
                $this->crawlState->markComplete($storeId, count($results), $issues, $saved, [
                    'run_id'          => $runId,
                    'severity_counts' => $severityCounts,
                ]);
                $this->logger->info(sprintf(
                    'Panth SEO crawl: store %d finished - %d page(s) crawled, %d issue(s), %d row(s) saved.',
                    $storeId,
                    count($results),
                    $issues,
                    $saved
                ));
            }

            return [
                'results'         => $results,
                'summary'         => $summary,
                'issues'          => $issues,
                'saved'           => $saved,
                'pages'           => count($results),
                'max_pages'       => $maxPages,
                'cancelled'       => $cancelled,
                'audit_issues'    => $auditIssues,
                'severity_counts' => $severityCounts,
                'run_id'          => $runId,
                'audit_stats'     => $this->siteAuditor?->getStats() ?? [],
            ];
        } catch (\Throwable $e) {
            $this->crawlState->markFailed($storeId, $e->getMessage());
            $this->logger->error(sprintf(
                'Panth SEO crawl: store %d failed: %s',
                $storeId,
                $e->getMessage()
            ));
            throw $e;
        }
    }

    public static function countBySeverity(array $issues): array
    {
        $counts = array_fill_keys(array_keys(Issue::SEVERITIES), 0);
        foreach ($issues as $issue) {
            if ($issue instanceof Issue) {
                $counts[$issue->severity]++;
            }
        }

        return $counts;
    }

    private function attachDetails(array $results, array $issues): array
    {
        $byUrl = [];
        foreach ($issues as $issue) {
            if ($issue instanceof Issue && $issue->detail !== '') {
                $byUrl[UrlHelper::normalize($issue->url)][] = $issue->detail;
            }
        }
        if ($byUrl === []) {
            return $results;
        }
        foreach ($results as $index => $result) {
            $details = $byUrl[UrlHelper::normalize($result->url)] ?? [];
            if ($details !== []) {
                $results[$index] = $result->withIssues(array_values(array_diff(array_unique($details), $result->issues)));
            }
        }

        return $results;
    }
}
