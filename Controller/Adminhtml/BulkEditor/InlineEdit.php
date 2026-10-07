<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\BulkEditor;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Panth\AdvancedSEO\Model\Cache\SeoCacheInvalidator;
use Panth\AdvancedSEO\Model\Meta\Cache as MetaCache;
use Panth\AdvancedSEO\Model\Meta\ResolvedRepository;

class InlineEdit extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::bulkeditor';

    public function __construct(
        Context $context,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryResource $categoryResource,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly ResourceConnection $resource,
        private readonly BackendSession $backendSession,
        private readonly JsonFactory $jsonFactory,
        private readonly ?ProductAction $productAction = null,
        private readonly ?SeoCacheInvalidator $cacheInvalidator = null,
        private readonly ?MetaCache $metaCache = null,
        private readonly ?ResolvedRepository $resolvedRepository = null
    ) {
        parent::__construct($context);
    }

    private function refresh(string $type, int $entityId, string $cacheType): void
    {
        try {
            $this->metaCache?->invalidateEntity($type, $entityId);
            $this->resolvedRepository?->deleteEntity($type, $entityId);
            $this->cacheInvalidator?->cleanEntityPages($cacheType, [$entityId]);
        } catch (\Throwable) {
            return;
        }
    }

    public function execute()
    {
        $resultJson = $this->jsonFactory->create();
        $error = false;
        $messages = [];

        $items = (array) $this->getRequest()->getParam('items', []);
        $entityType = (string) ($this->backendSession->getData('panth_seo_bulkeditor_type') ?? 'product');
        $storeId = $this->getRequest()->getParam('store');
        if ($storeId === null || $storeId === '') {
            $storeId = $this->backendSession->getData('panth_seo_bulkeditor_store');
        }
        $storeId = max(0, (int) $storeId);

        if (empty($items)) {
            return $resultJson->setData(['messages' => [__('Please correct the data sent.')], 'error' => true]);
        }

        foreach ($items as $entityId => $itemData) {
            try {
                match ($entityType) {
                    'category' => $this->saveCategory((int) $entityId, $itemData, $storeId),
                    'cms' => $this->saveCmsPage((int) $entityId, $itemData),
                    default => $this->saveProduct((int) $entityId, $itemData, $storeId),
                };
            } catch (\Throwable $e) {
                $error = true;
                $messages[] = __('[ID: %1] %2', (int) $entityId, $e->getMessage());
            }
        }

        return $resultJson->setData([
            'messages' => $messages,
            'error' => $error,
        ]);
    }

    private function saveProduct(int $entityId, array $data, int $storeId = 0): void
    {
        if ($this->productAction !== null) {
            $this->productRepository->getById($entityId, false, $storeId);
            $attributes = [];
            foreach (['meta_title', 'meta_description'] as $code) {
                if (isset($data[$code])) {
                    $attributes[$code] = (string) $data[$code];
                }
            }
            if ($attributes !== []) {
                $this->productAction->updateAttributes([$entityId], $attributes, $storeId);
                $this->refresh('product', $entityId, 'product');
            }
            return;
        }

        $product = $this->productRepository->getById($entityId, true, $storeId);
        $product->setStoreId($storeId);

        if (isset($data['meta_title'])) {
            $product->setMetaTitle((string) $data['meta_title']);
        }
        if (isset($data['meta_description'])) {
            $product->setMetaDescription((string) $data['meta_description']);
        }

        $this->productRepository->save($product);
    }

    private function saveCategory(int $entityId, array $data, int $storeId = 0): void
    {
        $conn = $this->resource->getConnection();
        $metaFields = ['meta_title', 'meta_description'];

        foreach ($metaFields as $attrCode) {
            if (!isset($data[$attrCode])) {
                continue;
            }
            $attribute = $this->categoryResource->getAttribute($attrCode);
            if (!$attribute || !$attribute->getAttributeId()) {
                continue;
            }
            $table = $attribute->getBackendTable();
            $conn->insertOnDuplicate($table, [
                'attribute_id' => $attribute->getAttributeId(),
                'store_id' => $storeId,
                'entity_id' => $entityId,
                'value' => (string) $data[$attrCode],
            ], ['value']);
        }
        $this->refresh('category', $entityId, 'category');
    }

    private function saveCmsPage(int $entityId, array $data): void
    {
        $page = $this->pageRepository->getById($entityId);

        if (isset($data['meta_title'])) {
            $page->setMetaTitle((string) $data['meta_title']);
        }
        if (isset($data['meta_description'])) {
            $page->setMetaDescription((string) $data['meta_description']);
        }

        $this->pageRepository->save($page);
    }
}
