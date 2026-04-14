<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Crosslink;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Ui\Component\MassAction\Filter;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Model\ResourceModel\Crosslink\CollectionFactory;

class MassDelete extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::crosslinks';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $ids = $collection->getAllIds();

            if (empty($ids)) {
                $this->messageManager->addErrorMessage(__('No crosslinks were selected.'));
                return $resultRedirect->setPath('*/*/');
            }

            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_crosslink');
            $deleted = $connection->delete($table, ['crosslink_id IN (?)' => $ids]);

            $this->messageManager->addSuccessMessage(
                __('A total of %1 crosslink(s) have been deleted.', $deleted)
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $resultRedirect->setPath('*/*/');
    }
}
