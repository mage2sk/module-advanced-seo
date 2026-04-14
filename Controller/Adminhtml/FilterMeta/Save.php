<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\FilterMeta;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;

class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::filter_meta';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $data = (array) $this->getRequest()->getPostValue();
        $resultRedirect = $this->resultRedirectFactory->create();
        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $id = (int) ($data['id'] ?? 0);
        $row = [
            'category_id'          => (int) ($data['category_id'] ?? 0),
            'attribute_code'       => (string) ($data['attribute_code'] ?? ''),
            'option_id'            => (int) ($data['option_id'] ?? 0),
            'store_id'             => (int) ($data['store_id'] ?? 0),
            'meta_title'           => (string) ($data['meta_title'] ?? ''),
            'meta_description'     => (string) ($data['meta_description'] ?? ''),
            'meta_keywords'        => (string) ($data['meta_keywords'] ?? ''),
            'breadcrumbs_priority' => (int) ($data['breadcrumbs_priority'] ?? 0),
        ];

        if ($row['category_id'] === 0 || $row['attribute_code'] === '') {
            $this->messageManager->addErrorMessage(__('Category ID and Attribute Code are required.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_category_filter_meta');
            if ($id > 0) {
                $connection->update($table, $row, ['id = ?' => $id]);
            } else {
                $connection->insert($table, $row);
                $id = (int) $connection->lastInsertId($table);
            }
            $this->messageManager->addSuccessMessage(__('Filter meta saved.'));
            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        return $resultRedirect->setPath('*/*/');
    }
}
