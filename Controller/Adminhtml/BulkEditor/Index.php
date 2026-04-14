<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\BulkEditor;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\View\Result\PageFactory;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Session as BackendSession;

class Index extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::templates';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly BackendSession $backendSession
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        // Read type from request (path/query param) — resolved only after dispatch,
        // NOT at controller construct time. Fall back to session, then 'product'.
        $type = (string) $this->getRequest()->getParam('type', '');
        if (!in_array($type, ['product', 'category', 'cms'], true)) {
            $type = (string) ($this->backendSession->getData('panth_seo_bulkeditor_type') ?? 'product');
        }
        if (!in_array($type, ['product', 'category', 'cms'], true)) {
            $type = 'product';
        }
        $this->backendSession->setData('panth_seo_bulkeditor_type', $type);

        $labels = ['product' => 'Products', 'category' => 'Categories', 'cms' => 'CMS Pages'];
        $label = $labels[$type] ?? 'Products';

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_AdvancedSEO::bulkeditor');
        $page->getConfig()->getTitle()->prepend(__('Bulk Meta Editor — %1', $label));
        return $page;
    }
}
