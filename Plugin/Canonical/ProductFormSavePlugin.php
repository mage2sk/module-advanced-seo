<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Canonical;

use Magento\Catalog\Controller\Adminhtml\Product\Save as ProductSaveController;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Canonical\CustomCanonicalRepository;
use Psr\Log\LoggerInterface;

/**
 * After the admin product save controller completes, persist any
 * custom_canonical_url value from the form to panth_seo_custom_canonical.
 */
class ProductFormSavePlugin
{
    private const ENTITY_TYPE = 'product';

    public function __construct(
        private readonly CustomCanonicalRepository $repository,
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
            $postData = (array) $this->request->getPostValue('product', []);
            $canonicalUrl = trim((string) ($postData['custom_canonical_url'] ?? ''));
            $productId    = (int) ($postData['entity_id']
                ?? $this->request->getParam('id', 0));
            $storeId      = (int) $this->request->getParam('store', 0);

            if ($productId <= 0) {
                return $result;
            }

            $this->persistCanonical(self::ENTITY_TYPE, $productId, $storeId, $canonicalUrl);
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO product canonical save failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Upsert or delete the custom canonical row.
     */
    private function persistCanonical(
        string $entityType,
        int $entityId,
        int $storeId,
        string $canonicalUrl
    ): void {
        $existingId = $this->findExistingId($entityType, $entityId, $storeId);

        if ($canonicalUrl === '') {
            // Remove override when the field is cleared.
            if ($existingId !== null) {
                $this->repository->deleteById($existingId);
            }
            return;
        }

        $data = [
            'source_entity_type' => $entityType,
            'source_entity_id'   => $entityId,
            'target_url'         => $canonicalUrl,
            'store_id'           => $storeId,
            'is_active'          => 1,
        ];

        if ($existingId !== null) {
            $data['canonical_id'] = $existingId;
        }

        $this->repository->save($data);
    }

    /**
     * Look up an existing canonical_id for the entity + store combination.
     */
    private function findExistingId(string $entityType, int $entityId, int $storeId): ?int
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('panth_seo_custom_canonical');

        $select = $connection->select()
            ->from($table, ['canonical_id'])
            ->where('source_entity_type = ?', $entityType)
            ->where('source_entity_id = ?', $entityId)
            ->where('store_id = ?', $storeId)
            ->limit(1);

        $id = $connection->fetchOne($select);

        return $id !== false ? (int) $id : null;
    }
}
