<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\FilterMeta;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;

class Edit extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::filter_meta';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly Registry $registry,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');
        $row = [];
        if ($id > 0) {
            $connection = $this->resource->getConnection();
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($this->resource->getTableName('panth_seo_category_filter_meta'))
                    ->where('id = ?', $id)
            ) ?: [];
        }
        $this->registry->register('panth_seo_category_filter_meta', $row, true);

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_AdvancedSEO::filter_meta');
        $page->getConfig()->getTitle()->prepend($id ? __('Edit Filter Meta') : __('New Filter Meta'));
        return $page;
    }
}
