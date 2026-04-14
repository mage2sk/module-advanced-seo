<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\CategoryFilterMeta;

use Magento\Framework\App\ResourceConnection;
use Panth\AdvancedSEO\Api\CategoryFilterMetaRepositoryInterface;

class Repository implements CategoryFilterMetaRepositoryInterface
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function getById(int $id)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_category_filter_meta');
        if (!$connection->isTableExists($table)) {
            return null;
        }
        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('filter_meta_id = ?', $id)
        );
        return $row ?: null;
    }

    public function save($entity)
    {
        return $entity;
    }

    public function deleteById(int $id): bool
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_category_filter_meta');
        if (!$connection->isTableExists($table)) {
            return false;
        }
        return (bool) $connection->delete($table, ['filter_meta_id = ?' => $id]);
    }
}
