<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\Crawler;
use Panth\AdvancedSEO\Model\Audit\IssueDetector;
use Psr\Log\LoggerInterface;

/**
 * Nightly cron: crawl every active store and persist results into
 * panth_seo_crawl_result for admin review.
 */
class CrawlAudit
{
    private const BATCH_INSERT_SIZE = 100;

    public function __construct(
        private readonly Crawler $crawler,
        private readonly IssueDetector $issueDetector,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        foreach ($this->storeRepository->getList() as $store) {
            $storeId = (int) $store->getId();
            if ($storeId === 0) {
                continue;
            }

            if (!$this->config->isCrawlAuditEnabled($storeId)) {
                continue;
            }

            try {
                $maxPages = $this->config->getCrawlDepth($storeId);
                $this->runForStore($storeId, $maxPages);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Panth SEO CrawlAudit: store %d failed: %s',
                    $storeId,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Crawl a single store and persist results.
     */
    private function runForStore(int $storeId, int $maxPages): void
    {
        $this->logger->info(sprintf('Panth SEO CrawlAudit: starting crawl for store %d (max %d pages)', $storeId, $maxPages));

        $rawResults = $this->crawler->crawl($storeId, $maxPages);
        $analysis   = $this->issueDetector->analyse($rawResults);

        /** @var \Panth\AdvancedSEO\Model\Audit\CrawlResult[] $results */
        $results = $analysis['results'];
        $summary = $analysis['summary'];

        $this->persistResults($storeId, $results);

        $totalIssues = (int) array_sum($summary);
        $this->logger->info(sprintf(
            'Panth SEO CrawlAudit: store %d complete — %d pages crawled, %d issues found',
            $storeId,
            count($results),
            $totalIssues
        ));
    }

    /**
     * Delete previous crawl results for the store and insert new ones.
     *
     * @param \Panth\AdvancedSEO\Model\Audit\CrawlResult[] $results
     */
    private function persistResults(int $storeId, array $results): void
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('panth_seo_crawl_result');

        if (!$connection->isTableExists($table)) {
            $this->logger->warning('Panth SEO CrawlAudit: table ' . $table . ' does not exist, skipping persistence');
            return;
        }

        // Remove stale results for this store
        $connection->delete($table, ['store_id = ?' => $storeId]);

        $now    = $this->dateTime->gmtDate();
        $buffer = [];

        foreach ($results as $result) {
            $row = $result->toArray();
            $row['store_id']   = $storeId;
            $row['crawled_at'] = $now;

            $buffer[] = $row;

            if (count($buffer) >= self::BATCH_INSERT_SIZE) {
                $connection->insertMultiple($table, $buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $connection->insertMultiple($table, $buffer);
        }
    }
}
