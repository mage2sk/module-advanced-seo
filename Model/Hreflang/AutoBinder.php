<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Hreflang;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Automatically binds product SKUs across stores into a shared hreflang group.
 *
 * Ran by the hreflang indexer. For each product SKU that exists in more than
 * one store view, a group keyed by "product:{sku}" is created if missing and
 * per-store members are upserted with their resolved canonical URL and the
 * store's locale code. Manual rows in `panth_seo_hreflang_member` that share
 * the same (group_id, store_id, entity_id) are preserved.
 */
class AutoBinder
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string[] $skus If empty, binds everything.
     */
    public function bindProducts(array $skus = []): int
    {
        $connection = $this->resource->getConnection();
        $groupTable = $this->resource->getTableName('panth_seo_hreflang_group');
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');

        $stores = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            $stores[] = [
                'id' => (int) $store->getId(),
                'locale' => $this->detectLocale((int) $store->getId()),
                'base' => rtrim($store->getBaseUrl(), '/') . '/',
            ];
        }
        if (count($stores) < 2) {
            return 0;
        }

        if ($skus === []) {
            $skus = $connection->fetchCol(
                $connection->select()->from($memberTable, ['entity_id'])->where('entity_type = ?', 'product')->distinct()
            );
            $productIds = array_map('intval', $skus);
            $skus = $productIds === []
                ? []
                : $connection->fetchCol(
                    $connection->select()
                        ->from($this->resource->getTableName('catalog_product_entity'), ['sku'])
                        ->where('entity_id IN (?)', $productIds)
                );
        }

        $count = 0;
        foreach ($skus as $sku) {
            $sku = (string) $sku;
            if ($sku === '') {
                continue;
            }
            $code = 'product:' . $sku;
            $groupId = (int) $connection->fetchOne(
                $connection->select()->from($groupTable, ['group_id'])->where('code = ?', $code)->limit(1)
            );
            if ($groupId === 0) {
                $connection->insert($groupTable, [
                    'code' => $code,
                    'entity_type' => 'product',
                    'notes' => 'Auto-bound by SKU',
                    'is_active' => 1,
                ]);
                $groupId = (int) $connection->lastInsertId($groupTable);
            }

            foreach ($stores as $store) {
                try {
                    $product = $this->productRepository->get($sku, false, $store['id']);
                } catch (NoSuchEntityException) {
                    continue;
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        sprintf('[panth_seo hreflang.autobind] sku=%s store=%d: %s', $sku, $store['id'], $e->getMessage())
                    );
                    continue;
                }
                $url = $store['base'] . ltrim((string) $product->getProductUrl(), '/');
                // Use product url from repo directly when possible.
                $productUrl = (string) $product->getProductUrl();
                if ($productUrl !== '') {
                    $url = $productUrl;
                }

                $existing = (int) $connection->fetchOne(
                    $connection->select()
                        ->from($memberTable, ['member_id'])
                        ->where('group_id = ?', $groupId)
                        ->where('store_id = ?', $store['id'])
                        ->where('entity_type = ?', 'product')
                        ->where('entity_id = ?', (int) $product->getId())
                        ->limit(1)
                );

                $data = [
                    'group_id' => $groupId,
                    'store_id' => $store['id'],
                    'entity_type' => 'product',
                    'entity_id' => (int) $product->getId(),
                    'locale' => $store['locale'],
                    'url' => $url,
                    'is_default' => 0,
                ];
                if ($existing > 0) {
                    $connection->update($memberTable, $data, ['member_id = ?' => $existing]);
                } else {
                    $connection->insert($memberTable, $data);
                    $count++;
                }
            }
        }
        return $count;
    }

    private function detectLocale(int $storeId): string
    {
        try {
            $locale = (string) $this->storeManager->getStore($storeId)->getConfig('general/locale/code');
        } catch (\Throwable) {
            return 'en';
        }
        if ($locale === '') {
            return 'en';
        }
        return str_replace('_', '-', $locale);
    }
}
