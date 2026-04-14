<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Feed;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;

class SaveField extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::manage';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultJson = $this->jsonFactory->create();
        $data = (array) $this->getRequest()->getPostValue();

        // Support inline editing (items array)
        $items = $data['items'] ?? null;
        if (is_array($items)) {
            return $this->processInlineEdit($items, $resultJson);
        }

        // Single field save (form submit)
        $feedId = (int) ($data['feed_id'] ?? 0);
        if ($feedId <= 0) {
            $this->messageManager->addErrorMessage(__('Feed ID is required.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        $fieldId = (int) ($data['field_id'] ?? 0);
        $row = $this->buildRow($data, $feedId);

        if ($row['feed_field'] === '') {
            $this->messageManager->addErrorMessage(__('Feed field name is required.'));
            return $this->resultRedirectFactory->create()->setPath('*/feed/fields', ['feed_id' => $feedId]);
        }

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_feed_field');

            if ($fieldId > 0) {
                $connection->update($table, $row, ['field_id = ?' => $fieldId]);
            } else {
                $connection->insert($table, $row);
            }

            $this->messageManager->addSuccessMessage(__('Field mapping saved.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->resultRedirectFactory->create()->setPath('*/feed/fields', ['feed_id' => $feedId]);
    }

    private function processInlineEdit(array $items, $resultJson)
    {
        $error = false;
        $messages = [];
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_feed_field');

        foreach ($items as $fieldId => $itemData) {
            try {
                $updateData = [];
                foreach (['feed_field', 'source_type', 'source_value', 'default_value', 'sort_order', 'is_required'] as $col) {
                    if (array_key_exists($col, $itemData)) {
                        $updateData[$col] = $itemData[$col];
                    }
                }
                if (!empty($updateData)) {
                    $connection->update($table, $updateData, ['field_id = ?' => (int) $fieldId]);
                }
            } catch (\Throwable $e) {
                $error = true;
                $messages[] = __('[Field ID: %1] %2', (int) $fieldId, $e->getMessage());
            }
        }

        return $resultJson->setData([
            'messages' => $messages,
            'error' => $error,
        ]);
    }

    private function buildRow(array $data, int $feedId): array
    {
        $validSourceTypes = ['attribute', 'static', 'template', 'parent_attribute'];
        $sourceType = (string) ($data['source_type'] ?? 'attribute');
        if (!in_array($sourceType, $validSourceTypes, true)) {
            $sourceType = 'attribute';
        }

        return [
            'feed_id'       => $feedId,
            'feed_field'    => trim((string) ($data['feed_field'] ?? '')),
            'source_type'   => $sourceType,
            'source_value'  => trim((string) ($data['source_value'] ?? '')),
            'default_value' => trim((string) ($data['default_value'] ?? '')) ?: null,
            'sort_order'    => max(0, (int) ($data['sort_order'] ?? 0)),
            'is_required'   => (int) ($data['is_required'] ?? 0),
        ];
    }
}
