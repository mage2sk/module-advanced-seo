<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Rule;

use Panth\AdvancedSEO\Api\RuleEvaluatorInterface;
use Panth\AdvancedSEO\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Panth\AdvancedSEO\Model\Rule\Condition\Combine;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Rule engine: loads active rules ordered by priority, evaluates them
 * against a context array and returns merged actions.
 */
class Engine implements RuleEvaluatorInterface
{
    private const CACHE_KEY_PREFIX = 'panth_seo_rules_';
    private const CACHE_LIFETIME = 3600;
    private const CACHE_TAG = 'panth_seo_rule';

    /** @var array<int,array<int,array<string,mixed>>> */
    private array $runtimeCache = [];

    public function __construct(
        private readonly RuleCollectionFactory $collectionFactory,
        private readonly Combine $combineFactory,
        private readonly SerializerInterface $serializer,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function evaluate(string $entityType, int $entityId, int $storeId, array $context = []): array
    {
        $context['entity_type'] = $entityType;
        $context['entity_id'] = $entityId;
        $context['store_id'] = $storeId;
        $rules = $this->loadRules($storeId, $entityType);

        $mergedActions = [
            'noindex' => null,
            'nofollow' => null,
            'canonical' => null,
            'title_template' => null,
            'description_template' => null,
            'og_template' => null,
            'matched_rules' => [],
        ];

        foreach ($rules as $rule) {
            try {
                $conditions = $this->serializer->unserialize((string)($rule['conditions_serialized'] ?? '[]'));
            } catch (\Throwable $e) {
                $this->logger->warning('Panth SEO rule conditions invalid', [
                    'rule_id' => $rule['rule_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (!$this->combineFactory->evaluate($conditions, $context)) {
                continue;
            }

            try {
                $actions = $this->serializer->unserialize((string)($rule['actions_serialized'] ?? '[]'));
            } catch (\Throwable $e) {
                $actions = [];
            }

            foreach ($actions as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                if ($mergedActions[$key] === null) {
                    $mergedActions[$key] = $value;
                }
            }

            $mergedActions['matched_rules'][] = (int)($rule['rule_id'] ?? 0);

            if (!empty($rule['stop_on_match'])) {
                break;
            }
        }

        return $mergedActions;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadRules(int $storeId, string $entityType): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $storeId . '_' . $entityType;
        if (isset($this->runtimeCache[$cacheKey])) {
            return $this->runtimeCache[$cacheKey];
        }

        $cached = $this->cache->load($cacheKey);
        if ($cached) {
            try {
                $decoded = $this->serializer->unserialize($cached);
                if (is_array($decoded)) {
                    return $this->runtimeCache[$cacheKey] = $decoded;
                }
            } catch (\Throwable $e) {
                // fall through to reload
            }
        }

        // Map entity type aliases: 'cms' and 'cms_page' should match each other
        $entityTypes = [$entityType, 'all'];
        if ($entityType === 'cms' || $entityType === 'cms_page') {
            $entityTypes[] = 'cms';
            $entityTypes[] = 'cms_page';
        }
        $entityTypes = array_unique($entityTypes);

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('is_active', 1)
            ->addFieldToFilter('entity_type', ['in' => $entityTypes])
            ->addFieldToFilter('store_id', ['in' => [$storeId, 0]])
            ->setOrder('priority', 'ASC');

        $rules = [];
        foreach ($collection as $rule) {
            $rules[] = $rule->getData();
        }

        $this->cache->save(
            $this->serializer->serialize($rules),
            $cacheKey,
            [self::CACHE_TAG],
            self::CACHE_LIFETIME
        );

        return $this->runtimeCache[$cacheKey] = $rules;
    }
}
