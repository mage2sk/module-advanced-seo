<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\CustomCanonical;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Model\Cache\SeoCacheInvalidator;
use Panth\AdvancedSEO\Model\Canonical\CustomCanonicalRepository;

class Delete extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::custom_canonical';

    public function __construct(
        Context $context,
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
        $id = (int) $this->getRequest()->getParam('id');
        $resultRedirect = $this->resultRedirectFactory->create();

        if ($id > 0) {
            try {
                $this->cleanPagesOf($id);
                $this->repository->deleteById($id);
                $this->messageManager->addSuccessMessage(__('Custom canonical deleted.'));
            } catch (\Throwable $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }

        return $resultRedirect->setPath('*/*/');
    }
}
