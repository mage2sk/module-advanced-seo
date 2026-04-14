<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Audit;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\Crawler;
use Panth\AdvancedSEO\Model\Audit\IssueDetector;

/**
 * Admin controller: trigger a manual SEO crawl audit for the current store scope.
 * Route: panth_seo/audit/crawl (POST)
 */
class Crawl extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::crawl_audit';

    public function __construct(
        Context $context,
        private readonly Crawler $crawler,
        private readonly IssueDetector $issueDetector,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        try {
            $storeId  = (int) $this->getRequest()->getParam('store', $this->storeManager->getStore()->getId());
            $maxPages = $this->config->getCrawlDepth($storeId);

            $rawResults = $this->crawler->crawl($storeId, $maxPages);
            $analysis   = $this->issueDetector->analyse($rawResults);

            /** @var \Panth\AdvancedSEO\Model\Audit\CrawlResult[] $results */
            $results = $analysis['results'];
            $summary = $analysis['summary'];

            $this->persistResults($storeId, $results);

            $totalIssues = (int) array_sum($summary);
            $connectionErrors = 0;
            foreach ($results as $r) {
                if ($r->statusCode === 0) {
                    $connectionErrors++;
                }
            }

            if ($connectionErrors > 0 && $connectionErrors === count($results)) {
                $this->messageManager->addWarningMessage(
                    (string) __(
                        'Crawl completed but could not connect to any pages (%1 failed). '
                        . 'This usually happens in Docker/local environments where the store URL (%2) '
                        . 'is not reachable from the server. The crawl works correctly on production servers.',
                        $connectionErrors,
                        $this->storeManager->getStore($storeId)->getBaseUrl()
                    )
                );
            } else {
                $this->messageManager->addSuccessMessage(
                    (string) __(
                        'Crawl audit complete: %1 pages crawled, %2 issues found.',
                        count($results),
                        $totalIssues
                    )
                );
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                (string) __('Crawl audit failed: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('*/audit/index');
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
            return;
        }

        $connection->delete($table, ['store_id = ?' => $storeId]);

        $now    = $this->dateTime->gmtDate();
        $buffer = [];

        foreach ($results as $result) {
            $row               = $result->toArray();
            $row['store_id']   = $storeId;
            $row['crawled_at'] = $now;

            $buffer[] = $row;

            if (count($buffer) >= 100) {
                $connection->insertMultiple($table, $buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $connection->insertMultiple($table, $buffer);
        }
    }
}
