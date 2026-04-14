<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\FilterRewrite;

use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;

class Edit extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::filter_rewrites';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_AdvancedSEO::filter_rewrite');
        $page->getConfig()->getTitle()->prepend(
            $id ? __('Edit Filter Rewrite #%1', $id) : __('New Filter Rewrite')
        );
        return $page;
    }
}
