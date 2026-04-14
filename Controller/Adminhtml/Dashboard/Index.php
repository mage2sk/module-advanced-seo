<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Dashboard;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\View\Result\PageFactory;
use Magento\Backend\App\Action\Context;

class Index extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::dashboard';

    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_AdvancedSEO::dashboard');
        $page->getConfig()->getTitle()->prepend(__('SEO Dashboard'));
        return $page;
    }
}
