<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Redirect;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Backend\App\Action\Context;

class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::redirects';

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

        $id = (int)($data['redirect_id'] ?? 0);
        $matchType = (string)($data['match_type'] ?? 'literal');
        if (!in_array($matchType, ['literal', 'regex', 'maintenance'], true)) {
            $this->messageManager->addErrorMessage(__('Invalid match type.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }
        $statusCode = (int)($data['status_code'] ?? 301);
        if (!in_array($statusCode, [301, 302, 303, 307, 308, 410], true)) {
            $this->messageManager->addErrorMessage(__('Invalid status code. Allowed: 301, 302, 303, 307, 308, 410.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }
        $target = trim((string)($data['target'] ?? ''));
        if (preg_match('#^(javascript|data|vbscript):#i', $target)) {
            $this->messageManager->addErrorMessage(__('Target URL must not use javascript:, data:, or vbscript: protocols.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }
        $startAt = !empty($data['start_at']) ? (string) $data['start_at'] : null;
        $finishAt = !empty($data['finish_at']) ? (string) $data['finish_at'] : null;
        $row = [
            'pattern' => mb_substr(trim((string)($data['pattern'] ?? '')), 0, 2048),
            'target' => mb_substr($target, 0, 2048),
            'match_type' => $matchType,
            'status_code' => $statusCode,
            'store_id' => (int)($data['store_id'] ?? 0),
            'is_active' => (int)($data['is_active'] ?? 1),
            'priority' => (int)($data['priority'] ?? 10),
            'start_at' => $startAt,
            'finish_at' => $finishAt,
            'updated_at' => $this->dateTime->gmtDate(),
        ];

        if ($row['pattern'] === '' || $row['target'] === '') {
            $this->messageManager->addErrorMessage(__('Pattern and Target URL are required.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_redirect');
            if ($id > 0) {
                $connection->update($table, $row, ['redirect_id = ?' => $id]);
            } else {
                $row['created_at'] = $this->dateTime->gmtDate();
                $connection->insert($table, $row);
                $id = (int)$connection->lastInsertId($table);
            }
            $this->messageManager->addSuccessMessage(__('Redirect saved.'));
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
