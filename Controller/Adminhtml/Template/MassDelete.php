<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Template;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Model\Cache\SeoCacheInvalidator;
use Panth\AdvancedSEO\Model\ResourceModel\Template\CollectionFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Backend\App\Action\Context;
use Magento\Ui\Component\MassAction\Filter;

class MassDelete extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::templates';

    private Filter $filter;

    private CollectionFactory $collectionFactory;

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly SeoCacheInvalidator $cacheInvalidator,
        ?Filter $filter = null,
        ?CollectionFactory $collectionFactory = null
    ) {
        parent::__construct($context);
        $this->filter = $filter ?? ObjectManager::getInstance()->get(Filter::class);
        $this->collectionFactory = $collectionFactory ?? ObjectManager::getInstance()->get(CollectionFactory::class);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        try {
            $ids = $this->filter->getCollection($this->collectionFactory->create())->getAllIds();
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $resultRedirect->setPath('*/*/');
        }
        $ids = array_filter(array_map('intval', $ids));
        if (!$ids) {
            $this->messageManager->addErrorMessage(__('Please select templates to delete.'));
            return $resultRedirect->setPath('*/*/');
        }
        try {
            $this->resource->getConnection()->delete(
                $this->resource->getTableName('panth_seo_template'),
                ['template_id IN (?)' => $ids]
            );
            $this->messageManager->addSuccessMessage(__('%1 template(s) deleted.', count($ids)));
            $this->cacheInvalidator->invalidateResolvedMeta();
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $resultRedirect->setPath('*/*/');
    }
}
