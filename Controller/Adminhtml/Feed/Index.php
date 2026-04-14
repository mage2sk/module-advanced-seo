<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Feed;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\View\Result\PageFactory;
use Magento\Backend\App\Action\Context;

class Index extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::manage';
    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_AdvancedSEO::feeds');
        $page->getConfig()->getTitle()->prepend(__('Product Feeds'));
        return $page;
    }
}
