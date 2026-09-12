<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Audit;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\CrawlRunner;
use Panth\AdvancedSEO\Model\Audit\CrawlState;
use Panth\AdvancedSEO\Model\Audit\CronActivity;

class Crawl extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::crawl_audit';

    public function __construct(
        Context $context,
        private readonly CrawlRunner $crawlRunner,
        private readonly CrawlState $crawlState,
        private readonly CronActivity $cronActivity,
        private readonly StoreManagerInterface $storeManager,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $storeId        = (int) $this->getRequest()->getParam('store', 0);

        try {
            $storeId  = $this->resolveStoreId($storeId);
            $store    = $this->storeRepository->getById($storeId);
            $maxPages = $this->config->getCrawlDepth($storeId);

            $state = $this->crawlState->get($storeId);

            if ($state['status'] === CrawlState::STATUS_PENDING) {
                $this->messageManager->addNoticeMessage(
                    (string) __('A crawl of store view "%1" is already queued.', $store->getName())
                );
                return $resultRedirect->setPath('*/audit/index', ['store' => $storeId]);
            }

            if ($state['status'] === CrawlState::STATUS_RUNNING && !$state['stale']) {
                $this->messageManager->addNoticeMessage(
                    (string) __(
                        'A crawl of store view "%1" is already running (%2 of %3 pages).',
                        $store->getName(),
                        (int) $state['crawled'],
                        (int) $state['max_pages']
                    )
                );
                return $resultRedirect->setPath('*/audit/index', ['store' => $storeId]);
            }

            if ($this->cronActivity->isActive()) {
                $this->crawlState->queue($storeId, $maxPages, $this->currentUserName());
                $this->messageManager->addSuccessMessage(
                    (string) __(
                        'Crawl of store view "%1" queued (up to %2 pages). It runs in the background within a minute; '
                        . 'this page shows the progress.',
                        $store->getName(),
                        $maxPages
                    )
                );

                return $resultRedirect->setPath('*/audit/index', ['store' => $storeId]);
            }

            $this->runSynchronously($storeId, (string) $store->getName(), $maxPages);
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                (string) __('Crawl audit failed: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('*/audit/index', ['store' => $storeId]);
    }

    private function runSynchronously(int $storeId, string $storeName, int $maxPages): void
    {
        $limit = min($maxPages, CrawlRunner::SYNC_PAGE_LIMIT);

        $outcome = $this->crawlRunner->run($storeId, $limit);

        $pages  = (int) $outcome['pages'];
        $issues = (int) $outcome['issues'];

        if ($pages === 0) {
            $this->messageManager->addWarningMessage(
                (string) __(
                    'Crawl of store view "%1" could not reach any page. Check that %2 is reachable from this server; '
                    . 'in a local container the store URL often is not.',
                    $storeName,
                    $this->storeManager->getStore($storeId)->getBaseUrl()
                )
            );
            return;
        }

        if ($limit < $maxPages) {
            $this->messageManager->addWarningMessage(
                (string) __(
                    'Magento cron does not appear to be running, so the crawl ran in this request and was capped at '
                    . '%1 of %2 pages (%3 issues found). Enable cron for a full background crawl, or run '
                    . 'bin/magento panth:seo:crawl --store=%4 on the server.',
                    $pages,
                    $maxPages,
                    $issues,
                    $storeId
                )
            );
            return;
        }

        $this->messageManager->addSuccessMessage(
            (string) __(
                'Crawl of store view "%1" complete: %2 pages crawled, %3 issues found.',
                $storeName,
                $pages,
                $issues
            )
        );
    }

    private function resolveStoreId(int $requested): int
    {
        if ($requested > 0) {
            return $requested;
        }

        $current = (int) $this->storeManager->getStore()->getId();
        if ($current > 0) {
            return $current;
        }

        foreach ($this->storeRepository->getList() as $store) {
            if ((int) $store->getId() > 0) {
                return (int) $store->getId();
            }
        }

        throw new \RuntimeException((string) __('No store view is available to crawl.'));
    }

    private function currentUserName(): string
    {
        $user = $this->_auth->getUser();

        return $user !== null ? (string) $user->getUserName() : '';
    }
}
