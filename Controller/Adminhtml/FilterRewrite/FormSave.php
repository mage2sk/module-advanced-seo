<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\FilterRewrite;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;

/**
 * Save controller for filter rewrite form submissions.
 */
class FormSave extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::filter_rewrites';

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

        $id = (int) ($data['rewrite_id'] ?? 0);
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_filter_rewrite');

        $row = [
            'attribute_code' => (string) ($data['attribute_code'] ?? ''),
            'option_id'      => (int) ($data['option_id'] ?? 0),
            'option_label'   => (string) ($data['option_label'] ?? ''),
            'rewrite_slug'   => (string) ($data['rewrite_slug'] ?? ''),
            'store_id'       => (int) ($data['store_id'] ?? 0),
            'is_active'      => (int) ($data['is_active'] ?? 1),
        ];

        try {
            if ($id > 0) {
                $connection->update($table, $row, ['rewrite_id = ?' => $id]);
            } else {
                $connection->insert($table, $row);
                $id = (int) $connection->lastInsertId($table);
            }
            $this->messageManager->addSuccessMessage(__('Filter rewrite saved.'));

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
