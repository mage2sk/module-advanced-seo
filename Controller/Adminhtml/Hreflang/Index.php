<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Hreflang;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\View\Result\PageFactory;
use Magento\Backend\App\Action\Context;

class Index extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::hreflang';

    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_AdvancedSEO::hreflang');
        $page->getConfig()->getTitle()->prepend(__('Hreflang Mapping'));
        return $page;
    }
}
