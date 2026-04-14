<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Admin;

use Magento\Catalog\Controller\Adminhtml\Product\Save as ProductSaveController;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Psr\Log\LoggerInterface;

/**
 * After the admin product save controller completes, persist the
 * custom_canonical_url value from the form to panth_seo_custom_canonical.
 *
 * The meta_robots and in_xml_sitemap fields are EAV attributes, so
 * Magento saves those automatically. Only custom_canonical_url needs
 * manual persistence.
 */
class ProductSeoFieldsSavePlugin
{
    private const TABLE       = 'panth_seo_custom_canonical';
    private const ENTITY_TYPE = 'product';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly RequestInterface $request,
        private readonly LoggerInterface $logger,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * @param ProductSaveController $subject
     * @param mixed                 $result
     * @return mixed
     */
    public function afterExecute(ProductSaveController $subject, mixed $result): mixed
    {
        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }

        try {
            $postData     = (array) $this->request->getPostValue('product', []);
            $canonicalUrl = trim((string) ($postData['custom_canonical_url'] ?? ''));
            $productId    = (int) ($postData['entity_id']
                ?? $this->request->getParam('id', 0));
            $storeId      = (int) $this->request->getParam('store', 0);

            if ($productId <= 0) {
                return $result;
            }

            $this->persistCanonical($productId, $storeId, $canonicalUrl);
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_AdvancedSEO] Product canonical save failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Upsert or delete the custom canonical row.
     */
    private function persistCanonical(int $entityId, int $storeId, string $canonicalUrl): void
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName(self::TABLE);
        $existingId = $this->findExistingId($entityId, $storeId);

        if ($canonicalUrl === '') {
            if ($existingId !== null) {
                $connection->delete($table, ['canonical_id = ?' => $existingId]);
            }
            return;
        }

        $data = [
            'source_entity_type' => self::ENTITY_TYPE,
            'source_entity_id'   => $entityId,
            'target_url'         => $canonicalUrl,
            'store_id'           => $storeId,
            'is_active'          => 1,
        ];

        if ($existingId !== null) {
            $connection->update($table, $data, ['canonical_id = ?' => $existingId]);
        } else {
            $connection->insert($table, $data);
        }
    }

    /**
     * Look up an existing canonical_id for the entity + store combination.
     */
    private function findExistingId(int $entityId, int $storeId): ?int
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName(self::TABLE);

        $select = $connection->select()
            ->from($table, ['canonical_id'])
            ->where('source_entity_type = ?', self::ENTITY_TYPE)
            ->where('source_entity_id = ?', $entityId)
            ->where('store_id = ?', $storeId)
            ->limit(1);

        $id = $connection->fetchOne($select);

        return $id !== false ? (int) $id : null;
    }
}
