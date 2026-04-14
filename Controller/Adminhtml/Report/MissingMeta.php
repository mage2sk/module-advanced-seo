<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Report;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\View\Result\PageFactory;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Session as BackendSession;

class MissingMeta extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::reports';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly BackendSession $backendSession
    ) {
        parent::__construct($context);

        // Set session IMMEDIATELY so DataProvider can read it during layout build
        $type = (string)$this->getRequest()->getParam('type', '');
        if ($type === '') {
            // Parse from URI path as fallback
            $uri = (string)$this->getRequest()->getRequestUri();
            if (preg_match('#/type/(product|category)(?:/|$)#', $uri, $m)) {
                $type = $m[1];
            }
        }
        if (!in_array($type, ['product', 'category'], true)) {
            $type = 'product';
        }
        $this->backendSession->setData('panth_seo_missing_meta_type', $type);
    }

    public function execute()
    {
        $type = (string)($this->backendSession->getData('panth_seo_missing_meta_type') ?? 'product');
        $label = $type === 'category' ? 'Categories' : 'Products';

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_AdvancedSEO::seo_dashboard');
        $page->getConfig()->getTitle()->prepend(__('Missing Meta Report — %1', $label));

        return $page;
    }
}
