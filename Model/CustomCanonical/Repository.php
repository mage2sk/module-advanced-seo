<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\CustomCanonical;

use Magento\Framework\App\ResourceConnection;
use Panth\AdvancedSEO\Api\CustomCanonicalRepositoryInterface;

class Repository implements CustomCanonicalRepositoryInterface
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function getById(int $id)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_custom_canonical');
        if (!$connection->isTableExists($table)) {
            return null;
        }
        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('canonical_id = ?', $id)
        );
        return $row ?: null;
    }

    public function save($entity)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_custom_canonical');
        $data = is_array($entity) ? $entity : (is_object($entity) ? $entity->getData() : []);
        $id = (int) ($data['canonical_id'] ?? 0);
        unset($data['canonical_id']);

        if ($id > 0) {
            $connection->update($table, $data, ['canonical_id = ?' => $id]);
            return $id;
        }

        $connection->insert($table, $data);
        return (int) $connection->lastInsertId($table);
    }

    public function deleteById(int $id): bool
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_custom_canonical');
        if (!$connection->isTableExists($table)) {
            return false;
        }
        return (bool) $connection->delete($table, ['canonical_id = ?' => $id]);
    }
}
