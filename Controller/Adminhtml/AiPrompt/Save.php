<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\AiPrompt;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Backend\App\Action\Context;

class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::manage';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $data = (array)$this->getRequest()->getPostValue();
        $resultRedirect = $this->resultRedirectFactory->create();
        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $id = (int)($data['prompt_id'] ?? 0);
        $entityType = (string)($data['entity_type'] ?? 'product');
        if (!in_array($entityType, ['product', 'category', 'cms_page', 'all'], true)) {
            $this->messageManager->addErrorMessage(__('Invalid entity type.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        $row = [
            'name'            => mb_substr((string)($data['name'] ?? ''), 0, 255),
            'entity_type'     => $entityType,
            'prompt_template' => (string)($data['prompt_template'] ?? ''),
            'is_default'      => (int)($data['is_default'] ?? 0),
            'is_active'       => (int)($data['is_active'] ?? 1),
            'sort_order'      => (int)($data['sort_order'] ?? 0),
            'updated_at'      => $this->dateTime->gmtDate(),
        ];

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_ai_prompt');

            // If marking as default, clear other defaults for this entity type
            if ($row['is_default']) {
                $connection->update(
                    $table,
                    ['is_default' => 0],
                    [
                        'entity_type = ?' => $entityType,
                        'prompt_id != ?' => $id,
                    ]
                );
            }

            if ($id > 0) {
                $connection->update($table, $row, ['prompt_id = ?' => $id]);
            } else {
                $row['created_at'] = $this->dateTime->gmtDate();
                $connection->insert($table, $row);
                $id = (int)$connection->lastInsertId($table);
            }
            $this->messageManager->addSuccessMessage(__('AI Prompt saved.'));
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
