<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\CustomCanonical;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Panth\AdvancedSEO\Model\Cache\SeoCacheInvalidator;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Model\Canonical\CustomCanonicalRepository;

class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::custom_canonical';

    public const PERSISTOR_KEY = 'panth_seo_custom_canonical';

    public function __construct(
        Context $context,
        private readonly CustomCanonicalRepository $repository,
        private readonly ?DataPersistorInterface $dataPersistor = null,
        private readonly ?SeoCacheInvalidator $cacheInvalidator = null
    ) {
        parent::__construct($context);
    }

    private function reject(\Magento\Framework\Phrase $message, array $data, int $id)
    {
        $this->messageManager->addErrorMessage($message);
        $this->dataPersistor?->set(self::PERSISTOR_KEY, $data);

        return $this->resultRedirectFactory->create()->setPath('*/*/edit', $id > 0 ? ['id' => $id] : []);
    }

    public static function isAllowedTargetUrl(string $url): bool
    {
        if ($url === '') {
            return true;
        }
        if (preg_match('/[\s<>"\'`\\\\]/', $url)) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }

        return preg_match('#^https?://[^/?\#]+#i', $url) === 1;
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
            || $sourceEntityType === ''
        ) {
            return $this->reject(__('Invalid entity type.'), $data, $id);
        }
        if ((int) ($data['source_entity_id'] ?? 0) <= 0) {
            return $this->reject(__('Enter the ID of the source entity.'), $data, $id);
        }
        $targetUrl = trim((string) ($data['target_url'] ?? ''));
        if (!self::isAllowedTargetUrl($targetUrl)) {
            return $this->reject(
                __('Enter the canonical URL as a full http:// or https:// URL or as a path that starts with /.'),
                $data,
                $id
            );
        }
        if ($targetUrl === '' && ($targetEntityType === '' || (int) ($data['target_entity_id'] ?? 0) <= 0)) {
            return $this->reject(__('Enter a canonical URL or choose a target entity type and ID.'), $data, $id);
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
            $previous = $id > 0 ? $this->repository->getById($id) : null;
            $savedId = $this->repository->save($row);
            $this->dataPersistor?->clear(self::PERSISTOR_KEY);
            $this->cacheInvalidator?->cleanEntityPages($sourceEntityType, [$row['source_entity_id']]);
            if ($previous !== null) {
                $this->cacheInvalidator?->cleanEntityPages(
                    (string) ($previous['source_entity_type'] ?? ''),
                    [(int) ($previous['source_entity_id'] ?? 0)]
                );
            }
            $this->messageManager->addSuccessMessage(__('Custom canonical saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['id' => $savedId]);
            }
        } catch (\Throwable $e) {
            return $this->reject(__('%1', $e->getMessage()), $data, $id);
        }

        return $resultRedirect->setPath('*/*/');
    }
}
