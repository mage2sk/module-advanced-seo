<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\ViewModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Model\InternalLinking\Suggester;
use Psr\Log\LoggerInterface;

/**
 * Hyva-safe ViewModel exposing internal-linking suggestions to templates.
 */
class RelatedLinks implements ArgumentInterface
{
    public function __construct(
        private readonly Suggester $suggester,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int,array{label:string,url:string,score:float}>
     */
    public function getSuggestions(string $entityType, int $entityId, int $limit = 5): array
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $raw = $this->suggester->suggest($entityType, $entityId, $storeId, $limit);
            if (empty($raw)) {
                return [];
            }
            return $this->hydrate($raw, $storeId);
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] related links viewmodel failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @param array<int,array{type:string,id:int,score:float}> $raw
     * @return array<int,array{label:string,url:string,score:float}>
     */
    private function hydrate(array $raw, int $storeId): array
    {
        if (empty($raw)) {
            return [];
        }
        $baseUrl = rtrim((string) $this->storeManager->getStore($storeId)->getBaseUrl(), '/') . '/';
        $byType  = [];
        foreach ($raw as $item) {
            $byType[$item['type']][] = (int) $item['id'];
        }

        $conn     = $this->resource->getConnection();
        $urlTable = $this->resource->getTableName('url_rewrite');
        $nameById = [];
        $pathById = [];

        foreach ($byType as $type => $ids) {
            if (empty($ids)) {
                continue;
            }
            $select = $conn->select()
                ->from($urlTable, ['entity_id', 'request_path'])
                ->where('entity_type = ?', $type)
                ->where('store_id = ?', $storeId)
                ->where('redirect_type = ?', 0)
                ->where('entity_id IN (?)', $ids);
            foreach ($conn->fetchAll($select) as $row) {
                $id = (int) $row['entity_id'];
                if (!isset($pathById[$type][$id])) {
                    $pathById[$type][$id] = (string) $row['request_path'];
                }
            }

            if ($type === 'product') {
                $eav = $this->resource->getTableName('catalog_product_entity_varchar');
                $attr = $this->resource->getTableName('eav_attribute');
                $select = $conn->select()
                    ->from(['v' => $eav], ['entity_id', 'value'])
                    ->join(['a' => $attr], 'a.attribute_id = v.attribute_id', [])
                    ->where('a.attribute_code = ?', 'name')
                    ->where('v.entity_id IN (?)', $ids)
                    ->where('v.store_id IN (?)', [0, $storeId]);
                foreach ($conn->fetchAll($select) as $row) {
                    $nameById[$type][(int) $row['entity_id']] = (string) $row['value'];
                }
            } elseif ($type === 'category') {
                $eav = $this->resource->getTableName('catalog_category_entity_varchar');
                $attr = $this->resource->getTableName('eav_attribute');
                $select = $conn->select()
                    ->from(['v' => $eav], ['entity_id', 'value'])
                    ->join(['a' => $attr], 'a.attribute_id = v.attribute_id', [])
                    ->where('a.attribute_code = ?', 'name')
                    ->where('v.entity_id IN (?)', $ids)
                    ->where('v.store_id IN (?)', [0, $storeId]);
                foreach ($conn->fetchAll($select) as $row) {
                    $nameById[$type][(int) $row['entity_id']] = (string) $row['value'];
                }
            }
        }

        $out = [];
        foreach ($raw as $item) {
            $type = $item['type'];
            $id   = (int) $item['id'];
            $path = $pathById[$type][$id] ?? null;
            $label = $nameById[$type][$id] ?? ('#' . $id);
            if ($path === null) {
                continue;
            }
            $out[] = [
                'label' => $label,
                'url'   => $baseUrl . ltrim($path, '/'),
                'score' => (float) $item['score'],
            ];
        }
        return $out;
    }
}
