<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Crosslink;

use Magento\Framework\App\ResourceConnection;
use Panth\AdvancedSEO\Api\CrosslinkRepositoryInterface;

class Repository implements CrosslinkRepositoryInterface
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function getById(int $id)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_crosslink');
        if (!$connection->isTableExists($table)) {
            return null;
        }
        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('crosslink_id = ?', $id)
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
        $table = $this->resource->getTableName('panth_seo_crosslink');
        if (!$connection->isTableExists($table)) {
            return false;
        }
        return (bool) $connection->delete($table, ['crosslink_id = ?' => $id]);
    }
}
