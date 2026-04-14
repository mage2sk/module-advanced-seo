<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Feed;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;

class NewField extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::manage';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
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

        // Store feed_id in session for the form data provider
        $this->backendSession->setData('panth_seo_feed_field_feed_id', $feedId);

        $fieldId = (int) $this->getRequest()->getParam('field_id');
        $title = $fieldId > 0 ? __('Edit Field Mapping') : __('Add Field Mapping');

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_AdvancedSEO::manage');
        $page->getConfig()->getTitle()->prepend($title);
        return $page;
    }
}
