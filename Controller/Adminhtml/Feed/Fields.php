<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Feed;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Result\PageFactory;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;

class Fields extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::manage';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly ResourceConnection $resource,
        private readonly BackendSession $backendSession
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $feedId = (int) $this->getRequest()->getParam('feed_id');
        if ($feedId <= 0) {
            $this->messageManager->addErrorMessage(__('Invalid feed profile ID.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        // Look up feed name
        $conn = $this->resource->getConnection();
        $feedName = $conn->fetchOne(
            $conn->select()
                ->from($this->resource->getTableName('panth_seo_feed_profile'), ['name'])
                ->where('feed_id = ?', $feedId)
        );

        if (!$feedName) {
            $this->messageManager->addErrorMessage(__('Feed profile not found.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        // Store feed_id in session so the data provider can filter by it
        $this->backendSession->setData('panth_seo_feed_field_feed_id', $feedId);

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_AdvancedSEO::manage');
        $page->getConfig()->getTitle()->prepend(__('Feed Field Mapping — %1', $feedName));
        return $page;
    }
}
