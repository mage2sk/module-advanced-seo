<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\BulkEditor;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Panth\AdvancedSEO\Model\Cache\SeoCacheInvalidator;
use Panth\AdvancedSEO\Model\Meta\Cache as MetaCache;
use Panth\AdvancedSEO\Model\Meta\ResolvedRepository;

class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::bulkeditor';

    public function __construct(
        Context $context,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ?ProductAction $productAction = null,
        private readonly ?SeoCacheInvalidator $cacheInvalidator = null,
        private readonly ?MetaCache $metaCache = null,
        private readonly ?ResolvedRepository $resolvedRepository = null
    ) {
        parent::__construct($context);
    }

    private function updateWithAction(int $id, array $row, int $storeId): void
    {
        $this->productRepository->getById($id, false, $storeId);
        $attributes = [];
        foreach (['meta_title', 'meta_description', 'meta_keyword'] as $code) {
            if (isset($row[$code])) {
                $attributes[$code] = (string) $row[$code];
            }
        }
        if ($attributes === []) {
            return;
        }
        $this->productAction->updateAttributes([$id], $attributes, $storeId);
        $this->metaCache?->invalidateEntity('product', $id);
        $this->resolvedRepository?->deleteEntity('product', $id);
        $this->cacheInvalidator?->cleanEntityPages('product', [$id]);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $rows = (array)$this->getRequest()->getParam('rows', []);
        $storeId = (int)$this->getRequest()->getParam('store_id', 0);
        $saved = 0;
        foreach ($rows as $row) {
            $id = (int)($row['entity_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            try {
                if ($this->productAction !== null) {
                    $this->updateWithAction($id, (array) $row, $storeId);
                    $saved++;
                    continue;
                }
                $product = $this->productRepository->getById($id, true, $storeId);
                $product->setStoreId($storeId);
                if (isset($row['meta_title'])) {
                    $product->setMetaTitle((string)$row['meta_title']);
                }
                if (isset($row['meta_description'])) {
                    $product->setMetaDescription((string)$row['meta_description']);
                }
                if (isset($row['meta_keyword'])) {
                    $product->setMetaKeyword((string)$row['meta_keyword']);
                }
                $this->productRepository->save($product);
                $saved++;
            } catch (\Throwable $e) {
                $this->messageManager->addErrorMessage(__('Entity %1: %2', $id, $e->getMessage()));
            }
        }
        $this->messageManager->addSuccessMessage(__('%1 row(s) saved.', $saved));
        return $resultRedirect->setPath('*/*/');
    }
}
