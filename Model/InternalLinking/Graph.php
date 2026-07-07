<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\InternalLinking;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

class Graph
{
    public const CACHE_KEY_PREFIX = 'panth_seo_link_graph_';
    public const CACHE_TAG        = 'panth_seo_link_graph';
    private const CACHE_TTL       = 3600;

    private array $memo = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function build(int $storeId): array
    {
        if (isset($this->memo[$storeId])) {
            return $this->memo[$storeId];
        }

        $key    = self::CACHE_KEY_PREFIX . $storeId;
        $cached = $this->cache->load($key);
        if (is_string($cached) && $cached !== '') {
            try {
                $decoded = $this->serializer->unserialize($cached);
                if (is_array($decoded)) {
                    return $this->memo[$storeId] = $decoded;
                }
            } catch (\Throwable) {
            }
        }

        $adjacency = [];
        $this->addEdges($adjacency, $this->loadProductCategoryEdges($storeId));
        $this->addEdges($adjacency, $this->loadCategoryTreeEdges());

        try {
            $this->cache->save(
                $this->serializer->serialize($adjacency),
                $key,
                [self::CACHE_TAG],
                self::CACHE_TTL
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] graph cache failed: ' . $e->getMessage());
        }

        return $this->memo[$storeId] = $adjacency;
    }

    public function clear(int $storeId): void
    {
        unset($this->memo[$storeId]);
        $this->cache->remove(self::CACHE_KEY_PREFIX . $storeId);
    }

    public static function node(string $type, int $id): string
    {
        return $type . ':' . $id;
    }

    private function addEdges(array &$adj, iterable $edges): void
    {
        foreach ($edges as [$from, $to, $weight]) {
            if ($from === $to) {
                continue;
            }
            $adj[$from][$to] = ($adj[$from][$to] ?? 0.0) + $weight;
            $adj[$to][$from] = ($adj[$to][$from] ?? 0.0) + ($weight * 0.5);
        }
    }

    private function loadProductCategoryEdges(int $storeId): iterable
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('catalog_category_product');
        $select = $conn->select()->from($table, ['category_id', 'product_id']);
        $stmt = $conn->query($select);
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            yield [
                self::node('category', (int) $row['category_id']),
                self::node('product', (int) $row['product_id']),
                1.0,
            ];
        }
    }

    private function loadCategoryTreeEdges(): iterable
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('catalog_category_entity');
        $select = $conn->select()->from($table, ['entity_id', 'parent_id'])->where('parent_id > 0');
        $stmt = $conn->query($select);
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            yield [
                self::node('category', (int) $row['parent_id']),
                self::node('category', (int) $row['entity_id']),
                0.7,
            ];
        }
    }
}
