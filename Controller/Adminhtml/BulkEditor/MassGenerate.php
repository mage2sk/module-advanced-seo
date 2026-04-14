<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\BulkEditor;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Panth\AdvancedSEO\Model\GenerationJob;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Backend\App\Action\Context;

class MassGenerate extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::templates';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly PublisherInterface $publisher
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $ids = (array)$this->getRequest()->getParam('selected', []);
        $ids = array_filter(array_map('intval', $ids));
        $entityType = (string)$this->getRequest()->getParam('entity_type', 'product');
        if (!in_array($entityType, ['product', 'category', 'cms_page'], true)) {
            $this->messageManager->addErrorMessage(__('Invalid entity type.'));
            return $resultRedirect->setPath('*/*/');
        }
        $storeId = (int)$this->getRequest()->getParam('store_id', 0);
        if (!$ids) {
            $this->messageManager->addErrorMessage(__('No rows selected.'));
            return $resultRedirect->setPath('*/*/');
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_generation_job');
        try {
            $uuid = bin2hex(random_bytes(16));
            $uuid = sprintf(
                '%s-%s-%s-%s-%s',
                substr($uuid, 0, 8),
                substr($uuid, 8, 4),
                substr($uuid, 12, 4),
                substr($uuid, 16, 4),
                substr($uuid, 20, 12)
            );
            $connection->insert($table, [
                'uuid' => $uuid,
                'entity_type' => $entityType,
                'store_id' => $storeId,
                'total' => count($ids),
                'processed' => 0,
                'failed' => 0,
                'status' => GenerationJob::STATUS_PENDING,
                'options' => json_encode(['entity_ids' => $ids]),
                'created_at' => $this->dateTime->gmtDate(),
                'updated_at' => $this->dateTime->gmtDate(),
            ]);
            $jobId = (int)$connection->lastInsertId($table);
            $this->publisher->publish('panth_seo.generate_meta', json_encode(['job_id' => $jobId]));
        } catch (\Throwable $e) {
            // continue
        }
        $this->messageManager->addSuccessMessage(__('Generation job queued for %1 entities.', count($ids)));
        return $resultRedirect->setPath('*/*/');
    }
}
