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

class InlineEdit extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::templates';

    public function __construct(
        Context $context,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryResource $categoryResource,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly ResourceConnection $resource,
        private readonly BackendSession $backendSession,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultJson = $this->jsonFactory->create();
        $error = false;
        $messages = [];

        $items = (array) $this->getRequest()->getParam('items', []);
        $entityType = (string) ($this->backendSession->getData('panth_seo_bulkeditor_type') ?? 'product');

        if (empty($items)) {
            return $resultJson->setData(['messages' => [__('Please correct the data sent.')], 'error' => true]);
        }

        foreach ($items as $entityId => $itemData) {
            try {
                match ($entityType) {
                    'category' => $this->saveCategory((int) $entityId, $itemData),
                    'cms' => $this->saveCmsPage((int) $entityId, $itemData),
                    default => $this->saveProduct((int) $entityId, $itemData),
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

    private function saveProduct(int $entityId, array $data): void
    {
        $product = $this->productRepository->getById($entityId, true, 0);

        if (isset($data['meta_title'])) {
            $product->setMetaTitle((string) $data['meta_title']);
        }
        if (isset($data['meta_description'])) {
            $product->setMetaDescription((string) $data['meta_description']);
        }

        $this->productRepository->save($product);
    }

    private function saveCategory(int $entityId, array $data): void
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
                'store_id' => 0,
                'entity_id' => $entityId,
                'value' => (string) $data[$attrCode],
            ], ['value']);
        }
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
