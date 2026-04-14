<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api;

/**
 * Repository interface for category filter meta overrides.
 */
interface CategoryFilterMetaRepositoryInterface
{
    /**
     * @param int $id
     * @return mixed
     */
    public function getById(int $id);

    /**
     * @param mixed $entity
     * @return mixed
     */
    public function save($entity);

    /**
     * @param int $id
     * @return bool
     */
    public function deleteById(int $id): bool;
}
