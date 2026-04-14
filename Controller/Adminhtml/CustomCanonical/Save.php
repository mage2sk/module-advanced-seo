<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\CustomCanonical;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Model\Canonical\CustomCanonicalRepository;

/**
 * Save controller for custom canonical URL overrides.
 */
class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::custom_canonical';

    public function __construct(
        Context $context,
        private readonly CustomCanonicalRepository $repository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $data = (array) $this->getRequest()->getPostValue();
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $id = (int) ($data['canonical_id'] ?? 0);
        $allowedEntityTypes = ['product', 'category', 'cms_page', ''];
        $sourceEntityType = (string) ($data['source_entity_type'] ?? '');
        $targetEntityType = (string) ($data['target_entity_type'] ?? '');
        if (!in_array($sourceEntityType, $allowedEntityTypes, true)
            || !in_array($targetEntityType, $allowedEntityTypes, true)
        ) {
            $this->messageManager->addErrorMessage(__('Invalid entity type.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }
        $targetUrl = (string) ($data['target_url'] ?? '');
        if ($targetUrl !== '' && preg_match('#^(javascript|data|vbscript):#i', $targetUrl)) {
            $this->messageManager->addErrorMessage(__('Target URL must not use javascript:, data:, or vbscript: protocols.'));
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        $row = [
            'source_entity_type' => $sourceEntityType,
            'source_entity_id'   => (int) ($data['source_entity_id'] ?? 0),
            'target_url'         => mb_substr($targetUrl, 0, 2048),
            'target_entity_type' => $targetEntityType,
            'target_entity_id'   => (int) ($data['target_entity_id'] ?? 0),
            'store_id'           => (int) ($data['store_id'] ?? 0),
            'is_active'          => (int) ($data['is_active'] ?? 1),
        ];

        if ($id > 0) {
            $row['canonical_id'] = $id;
        }

        try {
            $savedId = $this->repository->save($row);
            $this->messageManager->addSuccessMessage(__('Custom canonical saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['id' => $savedId]);
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        return $resultRedirect->setPath('*/*/');
    }
}
