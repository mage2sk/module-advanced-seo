<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Panth\AdvancedSEO\Api\Data\ResolvedMetaInterface;

/**
 * Thin wrapper over the collections frontend cache pool used to memoize
 * ResolvedMeta DTOs between requests. Invalidated by save observers via
 * entity/store tags.
 */
class Cache
{
    private const CACHE_TYPE = 'collections';
    private const KEY_PREFIX = 'panth_seo_meta_';
    private const LIFETIME   = 7200;

    private FrontendInterface $frontend;

    public function __construct(
        FrontendPool $frontendPool,
        private readonly SerializerInterface $serializer,
        private readonly ResolvedMetaFactory $resolvedFactory
    ) {
        $this->frontend = $frontendPool->get(self::CACHE_TYPE);
    }

    public function load(string $entityType, int $entityId, int $storeId): ?ResolvedMetaInterface
    {
        $raw = $this->frontend->load($this->key($entityType, $entityId, $storeId));
        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }
        try {
            $data = $this->serializer->unserialize($raw);
            if (!is_array($data)) {
                return null;
            }
            /** @var ResolvedMetaInterface $dto */
            $dto = $this->resolvedFactory->create();
            $dto->setStoreId((int) ($data['store_id'] ?? $storeId));
            $dto->setEntityType((string) ($data['entity_type'] ?? $entityType));
            $dto->setEntityId((int) ($data['entity_id'] ?? $entityId));
            $dto->setMetaTitle($data['meta_title'] ?? null);
            $dto->setMetaDescription($data['meta_description'] ?? null);
            $dto->setMetaKeywords($data['meta_keywords'] ?? null);
            $dto->setCanonicalUrl($data['canonical_url'] ?? null);
            $dto->setRobots($data['robots'] ?? null);
            $dto->setOgPayload(is_array($data['og_payload'] ?? null) ? $data['og_payload'] : []);
            $dto->setJsonldPayload(is_array($data['jsonld_payload'] ?? null) ? $data['jsonld_payload'] : []);
            $dto->setHreflangPayload(is_array($data['hreflang_payload'] ?? null) ? $data['hreflang_payload'] : []);
            $dto->setSource((string) ($data['source'] ?? 'cache'));
            return $dto;
        } catch (\Throwable) {
            return null;
        }
    }

    public function save(ResolvedMetaInterface $meta): void
    {
        $entityType = $meta->getEntityType();
        $entityId   = $meta->getEntityId();
        $storeId    = $meta->getStoreId();
        $data = [
            'store_id'         => $storeId,
            'entity_type'      => $entityType,
            'entity_id'        => $entityId,
            'meta_title'       => $meta->getMetaTitle(),
            'meta_description' => $meta->getMetaDescription(),
            'meta_keywords'    => $meta->getMetaKeywords(),
            'canonical_url'    => $meta->getCanonicalUrl(),
            'robots'           => $meta->getRobots(),
            'og_payload'       => $meta->getOgPayload(),
            'jsonld_payload'   => $meta->getJsonldPayload(),
            'hreflang_payload' => $meta->getHreflangPayload(),
            'source'           => $meta->getSource(),
        ];
        try {
            $this->frontend->save(
                $this->serializer->serialize($data),
                $this->key($entityType, $entityId, $storeId),
                $this->tagsFor($entityType, $entityId, $storeId),
                self::LIFETIME
            );
        } catch (\Throwable) {
            // swallow; cache is best-effort
        }
    }

    /**
     * @return string[]
     */
    public function tagsFor(string $entityType, int $entityId, int $storeId): array
    {
        return [
            'panth_seo_' . $entityType . '_' . $entityId,
            'panth_seo_store_' . $storeId,
            'panth_seo_' . $entityType,
        ];
    }

    public function invalidateEntity(string $entityType, int $entityId): void
    {
        $this->frontend->clean(
            \Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG,
            ['panth_seo_' . $entityType . '_' . $entityId]
        );
    }

    public function invalidateStore(int $storeId): void
    {
        $this->frontend->clean(
            \Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG,
            ['panth_seo_store_' . $storeId]
        );
    }

    private function key(string $entityType, int $entityId, int $storeId): string
    {
        return self::KEY_PREFIX . $storeId . '_' . $entityType . '_' . $entityId;
    }
}
