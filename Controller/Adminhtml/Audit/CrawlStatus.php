<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Audit;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Model\Audit\CrawlState;

class CrawlStatus extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::crawl_audit';

    public function __construct(
        Context $context,
        private readonly CrawlState $crawlState,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result  = $this->jsonFactory->create();
        $storeId = (int) $this->getRequest()->getParam('store', 0);

        if ($storeId <= 0) {
            return $result->setData(['error' => (string) __('A store view is required.')]);
        }

        try {
            $this->storeRepository->getById($storeId);
        } catch (\Throwable) {
            return $result->setData(['error' => (string) __('Store view %1 does not exist.', $storeId)]);
        }

        $state = $this->crawlState->get($storeId);

        return $result->setData([
            'status'    => $state['status'],
            'stale'     => $state['stale'],
            'crawled'   => $state['crawled'],
            'queued'    => $state['queued'],
            'max_pages' => $state['max_pages'],
            'pages'     => $state['pages'],
            'issues'    => $state['issues'],
            'saved'     => $state['saved'],
            'message'   => $state['message'],
            'stopping'  => $state['cancel_requested'],
            'active'    => $state['status'] === CrawlState::STATUS_PENDING
                || ($state['status'] === CrawlState::STATUS_RUNNING && !$state['stale']),
        ]);
    }
}
