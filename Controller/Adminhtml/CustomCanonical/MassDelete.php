<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\CustomCanonical;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Model\Cache\SeoCacheInvalidator;
use Panth\AdvancedSEO\Model\Canonical\CustomCanonicalRepository;
use Panth\AdvancedSEO\Model\ResourceModel\CustomCanonical\CollectionFactory;

class MassDelete extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::custom_canonical';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly CustomCanonicalRepository $repository,
        private readonly ?SeoCacheInvalidator $cacheInvalidator = null
    ) {
        parent::__construct($context);
    }

    private function cleanPagesOf(int $canonicalId): void
    {
        if ($this->cacheInvalidator === null) {
            return;
        }
        $row = $this->repository->getById($canonicalId);
        if ($row !== null) {
            $this->cacheInvalidator->cleanEntityPages(
                (string) ($row['source_entity_type'] ?? ''),
                [(int) ($row['source_entity_id'] ?? 0)]
            );
        }
    }

    public function execute()
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $deleted = 0;

        foreach ($collection as $item) {
            try {
                $this->cleanPagesOf((int) $item->getId());
                $this->repository->deleteById((int) $item->getId());
                $deleted++;
            } catch (\Throwable $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 record(s) have been deleted.', $deleted));

        return $this->resultRedirectFactory->create()->setPath('*/*/');
    }
}
