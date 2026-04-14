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

/**
 * Resolves the canonical URL for child products (simple/virtual) to their
 * parent composite product when the admin has enabled this behaviour.
 *
 * Checks in order: configurable, grouped, bundle.  Returns the first parent
 * product URL found, or null when the product is standalone.
 */
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

    /**
     * If the product is a child of a composite, return the parent product URL.
     *
     * @param int $productId  The child product entity ID.
     * @param int $storeId    Current store context.
     * @return string|null     Parent URL or null when not applicable.
     */
    public function resolve(int $productId, int $storeId): ?string
    {
        if (!$this->isEnabled($storeId)) {
            return null;
        }

        try {
            // 1. Configurable parent
            $parentId = $this->findConfigurableParent($productId);
            if ($parentId !== null) {
                return $this->getProductUrl($parentId, $storeId);
            }

            // 2. Grouped parent
            $parentId = $this->findGroupedParent($productId);
            if ($parentId !== null) {
                return $this->getProductUrl($parentId, $storeId);
            }

            // 3. Bundle parent
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

    /**
     * Find configurable parent IDs for the given simple product.
     */
    private function findConfigurableParent(int $childId): ?int
    {
        $parentIds = $this->configurableResource->getParentIdsByChild($childId);
        if (!empty($parentIds)) {
            return (int) reset($parentIds);
        }
        return null;
    }

    /**
     * Find grouped parent via the catalog_product_link table (link_type_id = 3).
     */
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

    /**
     * Find bundle parent via the catalog_product_bundle_selection table.
     */
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

    /**
     * Resolve a product entity ID to its frontend URL.
     */
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
