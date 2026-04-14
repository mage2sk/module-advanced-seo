<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Audit;

use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;

/**
 * Admin controller for the Crawl Audit Results grid.
 */
class CrawlResults extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::crawl_audit';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_AdvancedSEO::crawl_results');
        $page->getConfig()->getTitle()->prepend(__('Crawl Audit Results'));

        return $page;
    }
}
