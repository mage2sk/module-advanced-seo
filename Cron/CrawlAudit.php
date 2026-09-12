<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Cron;

use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\CrawlRunner;
use Panth\AdvancedSEO\Model\Audit\CrawlState;
use Psr\Log\LoggerInterface;

class CrawlAudit
{
    public function __construct(
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly CrawlRunner $crawlRunner,
        private readonly CrawlState $crawlState,
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

            if ($this->crawlState->isActive($storeId)) {
                $this->logger->info(sprintf(
                    'Panth SEO CrawlAudit: store %d already has a crawl queued or running, skipping the scheduled run.',
                    $storeId
                ));
                continue;
            }

            try {
                $this->crawlRunner->run($storeId, $this->config->getCrawlDepth($storeId));
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Panth SEO CrawlAudit: store %d failed: %s',
                    $storeId,
                    $e->getMessage()
                ));
            }
        }
    }
}
