<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Template;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Backend\App\Action\Context;

class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::templates';

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

        $id = (int)($data['template_id'] ?? 0);
        $entityType = (string)($data['entity_type'] ?? 'product');
        if (!in_array($entityType, ['product', 'category', 'cms_page'], true)) {
            $this->messageManager->addErrorMessage(__('Invalid entity type.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }
        $row = [
            'name' => mb_substr((string)($data['name'] ?? ''), 0, 255),
            'entity_type' => $entityType,
            'store_id' => (int)($data['store_id'] ?? 0),
            'meta_title' => (string)($data['meta_title'] ?? ''),
            'meta_description' => (string)($data['meta_description'] ?? ''),
            'meta_keywords' => (string)($data['meta_keywords'] ?? ''),
            'seo_name' => (string)($data['seo_name'] ?? ''),
            'og_title' => (string)($data['og_title'] ?? ''),
            'og_description' => (string)($data['og_description'] ?? ''),
            'og_image' => (string)($data['og_image'] ?? ''),
            'twitter_card' => (string)($data['twitter_card'] ?? ''),
            'robots' => (string)($data['robots'] ?? ''),
            'priority' => (int)($data['priority'] ?? 100),
            'is_active' => (int)($data['is_active'] ?? 1),
            'url_key_template' => (string)($data['url_key_template'] ?? ''),
            'conditions_serialized' => $this->buildConditionsJson($data),
            'is_cron_enabled' => (int)($data['is_cron_enabled'] ?? 0),
            'updated_at' => $this->dateTime->gmtDate(),
        ];

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_template');
            if ($id > 0) {
                $connection->update($table, $row, ['template_id = ?' => $id]);
            } else {
                $row['created_at'] = $this->dateTime->gmtDate();
                $connection->insert($table, $row);
                $id = (int)$connection->lastInsertId($table);
            }
            $this->messageManager->addSuccessMessage(__('Template saved.'));
            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        return $resultRedirect->setPath('*/*/');
    }

    private function buildConditionsJson(array $data): string
    {
        $attr = trim((string)($data['condition_attribute'] ?? ''));
        $val = trim((string)($data['condition_value'] ?? ''));
        if ($attr !== '' && $val !== '') {
            return json_encode([
                'type' => 'all',
                'conditions' => [['attribute' => $attr, 'operator' => 'in', 'value' => $val]]
            ]) ?: '{}';
        }
        $raw = $data['conditions_serialized'] ?? '';
        return is_string($raw) && $raw !== '' ? $raw : '{}';
    }
}
