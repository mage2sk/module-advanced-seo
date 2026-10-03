<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class EntityUrlMapper implements EntityLookupInterface
{
    private const TYPE_MAP = [
        'cms-page' => 'cms',
        'product'  => 'product',
        'category' => 'category',
    ];

    private array $entityCache = [];

    private array $attributeCache = [];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function entityForUrl(string $url, int $storeId): ?array
    {
        $path = ltrim(UrlHelper::path($url), '/');
        if ($path === '') {
            return null;
        }
        $key = $storeId . '|' . $path;
        if (array_key_exists($key, $this->entityCache)) {
            return $this->entityCache[$key];
        }
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName('url_rewrite'), ['entity_type', 'entity_id'])
                ->where('store_id = ?', $storeId)
                ->where('request_path IN (?)', array_unique([$path, rtrim($path, '/'), rtrim($path, '/') . '/']))
                ->where('entity_type IN (?)', array_keys(self::TYPE_MAP))
                ->order('redirect_type ASC')
                ->limit(1)
        );
        if (!is_array($row) || !isset(self::TYPE_MAP[$row['entity_type']])) {
            return $this->entityCache[$key] = null;
        }
        $type = self::TYPE_MAP[$row['entity_type']];
        $id   = (int) $row['entity_id'];

        return $this->entityCache[$key] = [
            'type'   => $type,
            'id'     => $id,
            'label'  => $this->label($connection, $type, $id, $storeId),
            'active' => $this->isActive($connection, $type, $id, $storeId),
        ];
    }

    public function resolvedTitle(string $url, int $storeId): ?string
    {
        $entity = $this->entityForUrl($url, $storeId);
        if ($entity === null) {
            return null;
        }
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('panth_seo_resolved');
        if (!$connection->isTableExists($table)) {
            return null;
        }
        $title = $connection->fetchOne(
            $connection->select()
                ->from($table, ['meta_title'])
                ->where('store_id = ?', $storeId)
                ->where('entity_type = ?', $entity['type'])
                ->where('entity_id = ?', $entity['id'])
                ->limit(1)
        );

        return is_string($title) && $title !== '' ? $title : null;
    }

    public function sourcesLinkingTo(string $url, ?array $entity, int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $path       = trim(UrlHelper::path($url), '/');
        if ($path === '') {
            return [];
        }
        $like    = '%' . $path . '%';
        $sources = [];

        $menu = $this->resource->getTableName('panth_megamenu_item');
        if ($connection->isTableExists($menu)) {
            $columns = $connection->describeTable($menu);
            $select  = $connection->select()->from($menu, ['item_id', 'title']);
            $where   = [$connection->quoteInto('url LIKE ?', $like)];
            $entityColumn = ['cms' => 'cms_page_id', 'product' => 'product_id', 'category' => 'category_id'][$entity['type'] ?? ''] ?? '';
            if ($entityColumn !== '' && isset($columns[$entityColumn])) {
                $where[] = $connection->quoteInto($entityColumn . ' = ?', (int) $entity['id']);
            }
            $select->where(implode(' OR ', $where))->limit(10);
            foreach ($connection->fetchAll($select) as $row) {
                $sources[] = sprintf('panth_megamenu_item #%d (%s)', (int) $row['item_id'], (string) $row['title']);
            }
        }

        $crosslink = $this->resource->getTableName('panth_seo_crosslink');
        if ($connection->isTableExists($crosslink)) {
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($crosslink, ['crosslink_id', 'keyword'])
                    ->where('url LIKE ?', $like)
                    ->limit(10)
            );
            foreach ($rows as $row) {
                $sources[] = sprintf('panth_seo_crosslink #%d (%s)', (int) $row['crosslink_id'], (string) $row['keyword']);
            }
        }

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('core_config_data'), ['path', 'scope', 'scope_id'])
                ->where('path LIKE ?', '%footer%')
                ->where('value LIKE ?', $like)
                ->limit(10)
        );
        foreach ($rows as $row) {
            $sources[] = sprintf('core_config_data %s (%s %d)', (string) $row['path'], (string) $row['scope'], (int) $row['scope_id']);
        }

        return $sources;
    }

    private function label(AdapterInterface $connection, string $type, int $id, int $storeId): string
    {
        try {
            if ($type === 'cms') {
                return (string) $connection->fetchOne(
                    $connection->select()->from($this->resource->getTableName('cms_page'), ['title'])->where('page_id = ?', $id)
                );
            }

            return (string) $this->eavValue($connection, $type, 'name', 'varchar', $id, $storeId);
        } catch (\Throwable) {
            return '';
        }
    }

    private function isActive(AdapterInterface $connection, string $type, int $id, int $storeId): bool
    {
        try {
            if ($type === 'cms') {
                return (int) $connection->fetchOne(
                    $connection->select()->from($this->resource->getTableName('cms_page'), ['is_active'])->where('page_id = ?', $id)
                ) === 1;
            }
            if ($type === 'product') {
                $status = $this->eavValue($connection, $type, 'status', 'int', $id, $storeId);

                return $status === null || (int) $status === 1;
            }
            $active = $this->eavValue($connection, $type, 'is_active', 'int', $id, $storeId);

            return $active === null || (int) $active === 1;
        } catch (\Throwable) {
            return true;
        }
    }

    private function eavValue(
        AdapterInterface $connection,
        string $type,
        string $attributeCode,
        string $backend,
        int $id,
        int $storeId
    ): ?string {
        $entityTypeCode = $type === 'product' ? 'catalog_product' : 'catalog_category';
        $cacheKey       = $entityTypeCode . '|' . $attributeCode;
        if (!isset($this->attributeCache[$cacheKey])) {
            $this->attributeCache[$cacheKey] = (int) $connection->fetchOne(
                $connection->select()
                    ->from(['ea' => $this->resource->getTableName('eav_attribute')], ['attribute_id'])
                    ->join(['et' => $this->resource->getTableName('eav_entity_type')], 'et.entity_type_id = ea.entity_type_id', [])
                    ->where('et.entity_type_code = ?', $entityTypeCode)
                    ->where('ea.attribute_code = ?', $attributeCode)
            );
        }
        $attributeId = $this->attributeCache[$cacheKey];
        if ($attributeId === 0) {
            return null;
        }
        $table = $this->resource->getTableName($entityTypeCode . '_entity_' . $backend);
        $value = $connection->fetchOne(
            $connection->select()
                ->from($table, ['value'])
                ->where('attribute_id = ?', $attributeId)
                ->where('entity_id = ?', $id)
                ->where('store_id IN (?)', [0, $storeId])
                ->order('store_id DESC')
                ->limit(1)
        );

        return $value === false || $value === null ? null : (string) $value;
    }
}
