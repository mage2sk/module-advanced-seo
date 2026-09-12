<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Cron;

use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Model\Audit\CrawlRunner;
use Panth\AdvancedSEO\Model\Audit\CrawlState;
use Psr\Log\LoggerInterface;

class ProcessCrawlQueue
{
    public function __construct(
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly CrawlState $crawlState,
        private readonly CrawlRunner $crawlRunner,
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

            $state = $this->crawlState->get($storeId);

            if ($state['status'] === CrawlState::STATUS_RUNNING) {
                if ($state['stale']) {
                    $this->crawlState->markFailed(
                        $storeId,
                        (string) __(
                            'The crawl stopped without finishing (no progress for more than %1 minutes). Run it again.',
                            (int) (CrawlState::STALE_AFTER_SECONDS / 60)
                        )
                    );
                    $this->logger->warning(sprintf(
                        'Panth SEO crawl: store %d was marked running but stopped reporting progress; marked failed.',
                        $storeId
                    ));
                }
                continue;
            }

            if ($state['status'] !== CrawlState::STATUS_PENDING) {
                continue;
            }

            try {
                $this->crawlRunner->run($storeId, (int) $state['max_pages']);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Panth SEO crawl: queued run for store %d failed: %s',
                    $storeId,
                    $e->getMessage()
                ));
            }
        }
    }
}
