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
        private readonly LoggerInterface $logger
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
                }
            );

            $analysis = $this->issueDetector->analyse($rawResults, $this->crawler->getRedirectMap());

            $results = $analysis['results'];
            $summary = $analysis['summary'];
            $issues  = (int) array_sum($summary);
            $saved   = $persist ? $this->persister->persist($storeId, $results) : 0;

            if ($cancelled) {
                $this->crawlState->markCancelled($storeId, count($results), $saved);
                $this->logger->info(sprintf(
                    'Panth SEO crawl: store %d cancelled after %d page(s).',
                    $storeId,
                    count($results)
                ));
            } else {
                $this->crawlState->markComplete($storeId, count($results), $issues, $saved);
                $this->logger->info(sprintf(
                    'Panth SEO crawl: store %d finished - %d page(s) crawled, %d issue(s), %d row(s) saved.',
                    $storeId,
                    count($results),
                    $issues,
                    $saved
                ));
            }

            return [
                'results'   => $results,
                'summary'   => $summary,
                'issues'    => $issues,
                'saved'     => $saved,
                'pages'     => count($results),
                'max_pages' => $maxPages,
                'cancelled' => $cancelled,
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
}
