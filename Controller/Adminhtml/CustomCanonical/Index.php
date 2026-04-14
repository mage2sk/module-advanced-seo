<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\CustomCanonical;

use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;

/**
 * Admin grid controller for custom canonical URL overrides.
 */
class Index extends AbstractAction
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
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_AdvancedSEO::manage');
        $page->getConfig()->getTitle()->prepend(__('Custom Canonical URLs'));
        return $page;
    }
}
