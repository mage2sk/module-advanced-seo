<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Url;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;

class RewriteRegenerator
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly ProductUrlRewriteGenerator $productUrlRewriteGenerator,
        private readonly CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator,
        private readonly UrlPersistInterface $urlPersist,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function regenerateProducts(int $storeId, array $ids = []): int
    {
        $stores = $this->resolveStoreIds($storeId);
        $totalGenerated = 0;

        foreach ($stores as $resolvedStoreId) {
            $this->storeManager->setCurrentStore($resolvedStoreId);

            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId($resolvedStoreId);
            $collection->addStoreFilter($resolvedStoreId);
            $collection->addAttributeToSelect(['url_key', 'url_path', 'visibility']);

            if (!empty($ids)) {
                $collection->addIdFilter($ids);
            }

            $collection->setPageSize(self::BATCH_SIZE);
            $pages = $collection->getLastPageNumber();

            for ($currentPage = 1; $currentPage <= $pages; $currentPage++) {
                $collection->setCurPage($currentPage);

                foreach ($collection as $product) {
                    $product->setStoreId($resolvedStoreId);

                    $this->urlPersist->deleteByData([
                        UrlRewrite::ENTITY_ID   => $product->getId(),
                        UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
                        UrlRewrite::STORE_ID    => $resolvedStoreId,
                    ]);

                    $newUrls = $this->productUrlRewriteGenerator->generate($product);
                    if (!empty($newUrls)) {
                        $this->urlPersist->replace($newUrls);
                        $totalGenerated += count($newUrls);
                    }
                }

                $collection->clear();
            }
        }

        return $totalGenerated;
    }

    public function regenerateCategories(int $storeId, array $ids = []): int
    {
        $stores = $this->resolveStoreIds($storeId);
        $totalGenerated = 0;

        foreach ($stores as $resolvedStoreId) {
            $this->storeManager->setCurrentStore($resolvedStoreId);

            $collection = $this->categoryCollectionFactory->create();
            $collection->setStoreId($resolvedStoreId);
            $collection->addAttributeToSelect(['url_key', 'url_path']);

            $collection->addAttributeToFilter('level', ['gt' => 1]);

            if (!empty($ids)) {
                $collection->addIdFilter($ids);
            }

            $collection->setPageSize(self::BATCH_SIZE);
            $pages = $collection->getLastPageNumber();

            for ($currentPage = 1; $currentPage <= $pages; $currentPage++) {
                $collection->setCurPage($currentPage);

                foreach ($collection as $category) {
                    $category->setStoreId($resolvedStoreId);

                    $this->urlPersist->deleteByData([
                        UrlRewrite::ENTITY_ID   => $category->getId(),
                        UrlRewrite::ENTITY_TYPE => CategoryUrlRewriteGenerator::ENTITY_TYPE,
                        UrlRewrite::STORE_ID    => $resolvedStoreId,
                    ]);

                    $newUrls = $this->categoryUrlRewriteGenerator->generate($category);
                    if (!empty($newUrls)) {
                        $this->urlPersist->replace($newUrls);
                        $totalGenerated += count($newUrls);
                    }
                }

                $collection->clear();
            }
        }

        return $totalGenerated;
    }

    private function resolveStoreIds(int $storeId): array
    {
        if ($storeId > 0) {
            return [$storeId];
        }

        $storeIds = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeIds[] = (int) $store->getId();
        }

        return $storeIds;
    }
}
