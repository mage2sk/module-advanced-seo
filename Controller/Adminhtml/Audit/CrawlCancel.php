<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Audit;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Model\Audit\CrawlState;

class CrawlCancel extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::crawl_audit';

    public function __construct(
        Context $context,
        private readonly CrawlState $crawlState
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $storeId        = (int) $this->getRequest()->getParam('store', 0);

        if ($storeId <= 0) {
            $this->messageManager->addErrorMessage((string) __('A store view is required.'));
            return $resultRedirect->setPath('*/audit/index');
        }

        if ($this->crawlState->requestCancel($storeId)) {
            $this->messageManager->addSuccessMessage(
                (string) __('The crawl will stop. Pages already crawled stay in the Crawl Results grid.')
            );
        } else {
            $this->messageManager->addNoticeMessage((string) __('No crawl is queued or running for this store view.'));
        }

        return $resultRedirect->setPath('*/audit/index', ['store' => $storeId]);
    }
}
