<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Redirect;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;

/**
 * Renders the redirect CSV import page with file-upload form.
 */
class ImportPage extends AbstractAction implements HttpGetActionInterface
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
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_AdvancedSEO::redirect');
        $page->getConfig()->getTitle()->prepend(__('Import Redirects'));
        return $page;
    }
}
