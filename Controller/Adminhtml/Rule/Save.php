<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Rule;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Backend\App\Action\Context;

class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::rules';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly SerializerInterface $serializer,
        private readonly DateTime $dateTime,
        private readonly TypeListInterface $cacheTypeList
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

        $id = (int)($data['rule_id'] ?? 0);
        $entityType = (string)($data['entity_type'] ?? 'product');
        if (!in_array($entityType, ['product', 'category', 'cms_page'], true)) {
            $this->messageManager->addErrorMessage(__('Invalid entity type.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }
        // Build conditions JSON from user-friendly form fields
        $conditionAttribute = trim((string)($data['condition_attribute'] ?? ''));
        $conditionValue = trim((string)($data['condition_value'] ?? ''));
        if ($conditionAttribute !== '' && $conditionValue !== '') {
            $conditions = [
                'type' => 'all',
                'conditions' => [
                    ['attribute' => $conditionAttribute, 'operator' => 'in', 'value' => $conditionValue]
                ]
            ];
            $conditionsSerialized = $this->serializer->serialize($conditions);
        } else {
            // Use raw JSON if provided (backward compat), or empty
            $raw = $data['conditions_serialized'] ?? '';
            $conditionsSerialized = is_string($raw) && $raw !== '' ? $raw : '{}';
        }

        // Build actions JSON from user-friendly form fields
        $actions = [];
        if (!empty($data['action_noindex'])) {
            $actions['noindex'] = (string)$data['action_noindex'];
        }
        if (!empty($data['action_title_template'])) {
            $actions['title_template'] = (string)$data['action_title_template'];
        }
        if (!empty($data['action_description_template'])) {
            $actions['description_template'] = (string)$data['action_description_template'];
        }
        if (!empty($data['action_canonical'])) {
            $actions['canonical'] = (string)$data['action_canonical'];
        }
        if (!empty($actions)) {
            $actionsSerialized = $this->serializer->serialize($actions);
        } else {
            $raw = $data['actions_serialized'] ?? '';
            $actionsSerialized = is_string($raw) && $raw !== '' ? $raw : '{}';
        }

        $row = [
            'name' => mb_substr((string)($data['name'] ?? ''), 0, 255),
            'entity_type' => $entityType,
            'store_id' => (int)($data['store_id'] ?? 0),
            'priority' => (int)($data['priority'] ?? 100),
            'is_active' => (int)($data['is_active'] ?? 1),
            'stop_on_match' => (int)($data['stop_on_match'] ?? 0),
            'conditions_serialized' => $conditionsSerialized,
            'actions_serialized' => $actionsSerialized,
            'updated_at' => $this->dateTime->gmtDate(),
        ];

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_rule');
            if ($id > 0) {
                $connection->update($table, $row, ['rule_id = ?' => $id]);
            } else {
                $row['created_at'] = $this->dateTime->gmtDate();
                $connection->insert($table, $row);
                $id = (int)$connection->lastInsertId($table);
            }
            $this->cacheTypeList->cleanType('config');
            $this->messageManager->addSuccessMessage(__('Rule saved.'));
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
