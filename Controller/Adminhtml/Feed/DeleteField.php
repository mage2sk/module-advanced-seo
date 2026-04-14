<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Feed;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;

class DeleteField extends AbstractAction implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::manage';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $fieldId = (int) $this->getRequest()->getParam('field_id');
        $feedId = (int) $this->getRequest()->getParam('feed_id');
        $resultRedirect = $this->resultRedirectFactory->create();

        if ($fieldId > 0) {
            try {
                $connection = $this->resource->getConnection();
                $table = $this->resource->getTableName('panth_seo_feed_field');

                // Get feed_id before deleting (for redirect)
                if ($feedId <= 0) {
                    $feedId = (int) $connection->fetchOne(
                        $connection->select()->from($table, ['feed_id'])->where('field_id = ?', $fieldId)
                    );
                }

                $connection->delete($table, ['field_id = ?' => $fieldId]);
                $this->messageManager->addSuccessMessage(__('Field mapping deleted.'));
            } catch (\Throwable $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }

        if ($feedId > 0) {
            return $resultRedirect->setPath('*/feed/fields', ['feed_id' => $feedId]);
        }

        return $resultRedirect->setPath('*/*/index');
    }
}
