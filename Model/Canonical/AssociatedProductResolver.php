<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Canonical;

use Magento\Bundle\Model\ResourceModel\Selection as BundleSelection;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\GroupedProduct\Model\ResourceModel\Product\Link as GroupedLink;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class AssociatedProductResolver
{
    private const XML_ENABLED = 'panth_seo/canonical/associated_product_canonical';

    public function __construct(
        private readonly ConfigurableResource $configurableResource,
        private readonly GroupedLink $groupedLink,
        private readonly BundleSelection $bundleSelection,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function resolve(int $productId, int $storeId): ?string
    {
        if (!$this->isEnabled($storeId)) {
            return null;
        }

        try {
            $parentId = $this->findConfigurableParent($productId);
            if ($parentId !== null) {
                return $this->getProductUrl($parentId, $storeId);
            }

            $parentId = $this->findGroupedParent($productId);
            if ($parentId !== null) {
                return $this->getProductUrl($parentId, $storeId);
            }

            $parentId = $this->findBundleParent($productId);
            if ($parentId !== null) {
                return $this->getProductUrl($parentId, $storeId);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO associated product canonical failed', [
                'product_id' => $productId,
                'error'      => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    private function findConfigurableParent(int $childId): ?int
    {
        $parentIds = $this->configurableResource->getParentIdsByChild($childId);
        if (!empty($parentIds)) {
            return (int) reset($parentIds);
        }
        return null;
    }

    private function findGroupedParent(int $childId): ?int
    {
        $connection = $this->groupedLink->getConnection();
        $table      = $this->groupedLink->getMainTable();

        $select = $connection->select()
            ->from($table, ['product_id'])
            ->where('linked_product_id = ?', $childId)
            ->where('link_type_id = ?', \Magento\GroupedProduct\Model\ResourceModel\Product\Link::LINK_TYPE_GROUPED)
            ->limit(1);

        $parentId = $connection->fetchOne($select);

        return $parentId !== false ? (int) $parentId : null;
    }

    private function findBundleParent(int $childId): ?int
    {
        $connection = $this->bundleSelection->getConnection();
        $table      = $this->bundleSelection->getMainTable();

        $select = $connection->select()
            ->from($table, ['parent_product_id'])
            ->where('product_id = ?', $childId)
            ->limit(1);

        $parentId = $connection->fetchOne($select);

        return $parentId !== false ? (int) $parentId : null;
    }

    private function getProductUrl(int $productId, int $storeId): ?string
    {
        try {
            $product = $this->productRepository->getById($productId, false, $storeId);
            return (string) $product->getUrlModel()->getUrl($product, [
                '_ignore_category' => true,
                '_scope'           => $storeId,
            ]);
        } catch (NoSuchEntityException) {
            return null;
        }
    }
}
