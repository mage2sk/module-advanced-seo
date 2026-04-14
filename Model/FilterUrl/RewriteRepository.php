<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\FilterUrl;

use Panth\AdvancedSEO\Model\ResourceModel\FilterRewrite\CollectionFactory;

/**
 * In-memory cache of active filter rewrites for fast lookup by attribute+option or slug.
 */
class RewriteRepository
{
    /**
     * Keyed by storeId, then "attribute_code|option_id" => slug.
     * @var array<int, array<string, string>>
     */
    private array $slugMap = [];

    /**
     * Keyed by storeId, then slug => [attribute_code, option_id].
     * @var array<int, array<string, array{string, int}>>
     */
    private array $reverseMap = [];

    /** @var array<int, bool> */
    private array $loaded = [];

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * Get the friendly slug for a given attribute option.
     */
    public function getSlug(string $attrCode, int $optionId, int $storeId): ?string
    {
        $this->preload($storeId);
        $key = $attrCode . '|' . $optionId;
        return $this->slugMap[$storeId][$key] ?? null;
    }

    /**
     * Reverse-lookup: given a slug, return [attribute_code, option_id] or null.
     *
     * @return array{string, int}|null
     */
    public function getBySlug(string $slug, int $storeId): ?array
    {
        $this->preload($storeId);
        return $this->reverseMap[$storeId][$slug] ?? null;
    }

    /**
     * Load all active rewrites for the given store (and store 0) into memory.
     */
    private function preload(int $storeId): void
    {
        if (isset($this->loaded[$storeId])) {
            return;
        }

        $this->slugMap[$storeId] = [];
        $this->reverseMap[$storeId] = [];

        /** @var \Panth\AdvancedSEO\Model\ResourceModel\FilterRewrite\Collection $collection */
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter('store_id', ['in' => [0, $storeId]]);
        // Store-specific rewrites override store 0; load store 0 first.
        $collection->setOrder('store_id', 'ASC');

        /** @var FilterRewrite $item */
        foreach ($collection as $item) {
            $key = $item->getAttributeCode() . '|' . $item->getOptionId();
            $slug = $item->getRewriteSlug();

            $this->slugMap[$storeId][$key] = $slug;
            $this->reverseMap[$storeId][$slug] = [
                $item->getAttributeCode(),
                $item->getOptionId(),
            ];
        }

        $this->loaded[$storeId] = true;
    }

    /**
     * Clear in-memory cache (useful in tests or after admin saves).
     */
    public function reset(): void
    {
        $this->slugMap = [];
        $this->reverseMap = [];
        $this->loaded = [];
    }
}
