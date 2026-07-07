<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Api\Data\StoreInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Api\Data\ResolvedMetaInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Logger\Logger as SeoDebugLogger;
use Psr\Log\LoggerInterface;

class ResolvedMeta implements IndexerActionInterface, MviewActionInterface
{
    public const INDEXER_ID = 'panth_seo_resolved_meta';

    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly MetaResolverInterface $metaResolver,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly ?SeoConfig $seoConfig = null,
        private readonly ?SeoDebugLogger $seoDebugLogger = null
    ) {
    }

    private function debug(string $message, array $context = []): void
    {
        if ($this->seoDebugLogger === null || $this->seoConfig === null) {
            return;
        }
        if (!$this->seoConfig->isDebug()) {
            return;
        }
        $this->seoDebugLogger->debug($message, $context);
    }

    public function executeFull(): void
    {
        $this->debug('panth_seo: indexer.run', [
            'indexer' => self::INDEXER_ID,
            'mode' => 'full',
        ]);
        $connection = $this->resource->getConnection();
        $resolvedTable = $this->resource->getTableName('panth_seo_resolved');
        $connection->delete($resolvedTable);

        foreach ($this->storeRepository->getList() as $store) {
            if ((int) $store->getId() === 0) {
                continue;
            }
            $this->reindexStore($store);
        }
    }

    public function execute($ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->debug('panth_seo: indexer.run', [
            'indexer' => self::INDEXER_ID,
            'mode' => 'partial',
            'id_count' => count($ids),
        ]);
        foreach ($this->storeRepository->getList() as $store) {
            if ((int) $store->getId() === 0) {
                continue;
            }
            foreach ([
                MetaResolverInterface::ENTITY_PRODUCT,
                MetaResolverInterface::ENTITY_CATEGORY,
                MetaResolverInterface::ENTITY_CMS,
            ] as $type) {
                $this->reindexEntities($store, $type, array_map('intval', $ids));
            }
        }
    }

    public function executeList(array $ids): void
    {
        $this->execute($ids);
    }

    public function executeRow($id): void
    {
        $this->execute([(int) $id]);
    }

    private function reindexStore(StoreInterface $store): void
    {
        $storeId = (int) $store->getId();
        $connection = $this->resource->getConnection();

        $productIds = $connection->fetchCol(
            $connection->select()
                ->from(['cpe' => $this->resource->getTableName('catalog_product_entity')], ['entity_id'])
        );
        $this->reindexEntities($store, MetaResolverInterface::ENTITY_PRODUCT, array_map('intval', $productIds));

        $categoryIds = $connection->fetchCol(
            $connection->select()
                ->from(['cce' => $this->resource->getTableName('catalog_category_entity')], ['entity_id'])
                ->where('cce.level > ?', 1)
        );
        $this->reindexEntities($store, MetaResolverInterface::ENTITY_CATEGORY, array_map('intval', $categoryIds));

        $cmsIds = $connection->fetchCol(
            $connection->select()
                ->from(['cps' => $this->resource->getTableName('cms_page_store')], ['page_id'])
                ->where('cps.store_id IN (?)', [0, $storeId])
        );
        $this->reindexEntities($store, MetaResolverInterface::ENTITY_CMS, array_map('intval', array_unique($cmsIds)));
    }

    private function reindexEntities(StoreInterface $store, string $entityType, array $entityIds): void
    {
        if ($entityIds === []) {
            return;
        }
        $storeId = (int) $store->getId();
        foreach (array_chunk($entityIds, self::BATCH_SIZE) as $chunk) {
            try {
                $resolved = $this->metaResolver->resolveBatch($entityType, $chunk, $storeId);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf(
                        '[panth_seo_resolved_meta] resolveBatch failed store=%d type=%s: %s',
                        $storeId,
                        $entityType,
                        $e->getMessage()
                    )
                );
                continue;
            }
            $this->writeRows($storeId, $entityType, $resolved);
        }
    }

    private function writeRows(int $storeId, string $entityType, array $resolved): void
    {
        if ($resolved === []) {
            return;
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_resolved');

        $rows = [];
        foreach ($resolved as $entityId => $row) {
            $rows[] = [
                'store_id'         => $storeId,
                'entity_type'      => $entityType,
                'entity_id'        => (int) $entityId,
                'meta_title'       => $row->getMetaTitle(),
                'meta_description' => $row->getMetaDescription(),
                'meta_keywords'    => $row->getMetaKeywords(),
                'canonical_url'    => $row->getCanonicalUrl(),
                'robots'           => $row->getRobots(),
                'og_payload'       => $this->encode($row->getOgPayload()),
                'jsonld_payload'   => $this->encode($row->getJsonldPayload()),
                'hreflang_payload' => $this->encode($row->getHreflangPayload()),
                'source'           => $row->getSource() ?: 'template',
            ];
        }
        $connection->insertOnDuplicate(
            $table,
            $rows,
            [
                'meta_title',
                'meta_description',
                'meta_keywords',
                'canonical_url',
                'robots',
                'og_payload',
                'jsonld_payload',
                'hreflang_payload',
                'source',
            ]
        );
    }

    private function encode(array $payload): ?string
    {
        if ($payload === []) {
            return null;
        }
        try {
            return $this->json->serialize($payload);
        } catch (\Throwable) {
            return null;
        }
    }
}
