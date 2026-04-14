<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\CustomCanonical;

use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;

class Edit extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::custom_canonical';

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
        $page->setActiveMenu('Panth_AdvancedSEO::manage');
        $page->getConfig()->getTitle()->prepend(
            $id ? __('Edit Custom Canonical #%1', $id) : __('New Custom Canonical')
        );
        return $page;
    }
}
