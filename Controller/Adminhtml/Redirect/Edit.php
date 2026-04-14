<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Redirect;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\View\Result\PageFactory;
use Magento\Backend\App\Action\Context;

class Edit extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::redirects';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_AdvancedSEO::redirect');
        $page->getConfig()->getTitle()->prepend($id ? __('Edit Redirect #%1', $id) : __('New Redirect'));
        return $page;
    }
}
