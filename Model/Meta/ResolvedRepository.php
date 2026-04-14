<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Panth\AdvancedSEO\Api\Data\ResolvedMetaInterface;

/**
 * Read/write access to `panth_seo_resolved`. Used by the resolver for the
 * fast path (single-row lookup) and by the indexer for bulk writes.
 */
class ResolvedRepository
{
    private const TABLE = 'panth_seo_resolved';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ResolvedMetaFactory $resolvedFactory,
        private readonly SerializerInterface $serializer
    ) {
    }

    public function find(string $entityType, int $entityId, int $storeId): ?ResolvedMetaInterface
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where('store_id = ?', $storeId)
            ->where('entity_type = ?', $entityType)
            ->where('entity_id = ?', $entityId)
            ->limit(1);

        $row = $connection->fetchRow($select);
        if (!$row) {
            return null;
        }
        return $this->hydrate($row);
    }

    /**
     * @param int[] $entityIds
     * @return array<int,ResolvedMetaInterface>
     */
    public function findMany(string $entityType, array $entityIds, int $storeId): array
    {
        if ($entityIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where('store_id = ?', $storeId)
            ->where('entity_type = ?', $entityType)
            ->where('entity_id IN (?)', $entityIds);

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $dto = $this->hydrate($row);
            $out[$dto->getEntityId()] = $dto;
        }
        return $out;
    }

    public function save(ResolvedMetaInterface $meta): void
    {
        $connection = $this->resource->getConnection();
        $data = [
            'store_id'         => $meta->getStoreId(),
            'entity_type'      => $meta->getEntityType(),
            'entity_id'        => $meta->getEntityId(),
            'meta_title'       => $meta->getMetaTitle(),
            'meta_description' => $meta->getMetaDescription(),
            'meta_keywords'    => $meta->getMetaKeywords(),
            'canonical_url'    => $meta->getCanonicalUrl(),
            'robots'           => $meta->getRobots(),
            'og_payload'       => $this->encode($meta->getOgPayload()),
            'jsonld_payload'   => $this->encode($meta->getJsonldPayload()),
            'hreflang_payload' => $this->encode($meta->getHreflangPayload()),
            'source'           => $meta->getSource() ?: 'template',
        ];
        $connection->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            $data,
            array_keys($data)
        );
    }

    public function deleteEntity(string $entityType, int $entityId, ?int $storeId = null): void
    {
        $connection = $this->resource->getConnection();
        $where = [
            'entity_type = ?' => $entityType,
            'entity_id = ?'   => $entityId,
        ];
        if ($storeId !== null) {
            $where['store_id = ?'] = $storeId;
        }
        $connection->delete($this->resource->getTableName(self::TABLE), $where);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): ResolvedMetaInterface
    {
        /** @var ResolvedMetaInterface $dto */
        $dto = $this->resolvedFactory->create();
        $dto->setData(ResolvedMetaInterface::RESOLVED_ID, isset($row['resolved_id']) ? (int) $row['resolved_id'] : null);
        $dto->setStoreId((int) ($row['store_id'] ?? 0));
        $dto->setEntityType((string) ($row['entity_type'] ?? ''));
        $dto->setEntityId((int) ($row['entity_id'] ?? 0));
        $dto->setMetaTitle($row['meta_title'] ?? null);
        $dto->setMetaDescription($row['meta_description'] ?? null);
        $dto->setMetaKeywords($row['meta_keywords'] ?? null);
        $dto->setCanonicalUrl($row['canonical_url'] ?? null);
        $dto->setRobots($row['robots'] ?? null);
        $dto->setOgPayload($this->decode($row['og_payload'] ?? null));
        $dto->setJsonldPayload($this->decode($row['jsonld_payload'] ?? null));
        $dto->setHreflangPayload($this->decode($row['hreflang_payload'] ?? null));
        $dto->setSource((string) ($row['source'] ?? 'template'));
        return $dto;
    }

    /** @return array<string,mixed> */
    private function decode(?string $payload): array
    {
        if ($payload === null || $payload === '') {
            return [];
        }
        try {
            $decoded = $this->serializer->unserialize($payload);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $payload */
    private function encode(array $payload): ?string
    {
        if ($payload === []) {
            return null;
        }
        try {
            return $this->serializer->serialize($payload);
        } catch (\Throwable) {
            return null;
        }
    }
}
