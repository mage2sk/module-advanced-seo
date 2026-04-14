<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Crosslink;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;

class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::crosslinks';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime
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

        $id = (int) ($data['crosslink_id'] ?? 0);
        $referenceType = (string) ($data['reference_type'] ?? 'url');
        if (!in_array($referenceType, ['url', 'product_sku', 'category_id'], true)) {
            $referenceType = 'url';
        }
        $activeFrom = !empty($data['active_from']) ? (string) $data['active_from'] : null;
        $activeTo = !empty($data['active_to']) ? (string) $data['active_to'] : null;
        $row = [
            'keyword'          => trim((string) ($data['keyword'] ?? '')),
            'url'              => trim((string) ($data['url'] ?? '')),
            'url_title'        => trim((string) ($data['url_title'] ?? '')),
            'max_replacements' => max(1, (int) ($data['max_replacements'] ?? 1)),
            'nofollow'         => (int) ($data['nofollow'] ?? 0),
            'priority'         => (int) ($data['priority'] ?? 0),
            'is_active'        => (int) ($data['is_active'] ?? 1),
            'in_product'       => (int) ($data['in_product'] ?? 1),
            'in_category'      => (int) ($data['in_category'] ?? 1),
            'in_cms'           => (int) ($data['in_cms'] ?? 1),
            'store_id'         => (int) ($data['store_id'] ?? 0),
            'reference_type'   => $referenceType,
            'reference_value'  => trim((string) ($data['reference_value'] ?? '')),
            'active_from'      => $activeFrom,
            'active_to'        => $activeTo,
        ];

        if ($row['keyword'] === '') {
            $this->messageManager->addErrorMessage(__('Keyword is required.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }
        if ($referenceType === 'url' && $row['url'] === '') {
            $this->messageManager->addErrorMessage(__('URL is required when reference type is "url".'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }
        if ($referenceType !== 'url' && $row['reference_value'] === '') {
            $this->messageManager->addErrorMessage(__('Reference value is required when using product SKU or category ID reference type.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }
        if (preg_match('#^(javascript|data|vbscript):#i', $row['url'])) {
            $this->messageManager->addErrorMessage(__('URL must not use javascript:, data:, or vbscript: protocols.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_crosslink');

            if ($id > 0) {
                $connection->update($table, $row, ['crosslink_id = ?' => $id]);
            } else {
                $row['created_at'] = $this->dateTime->gmtDate();
                $connection->insert($table, $row);
                $id = (int) $connection->lastInsertId($table);
            }

            $this->messageManager->addSuccessMessage(__('Crosslink saved.'));

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
