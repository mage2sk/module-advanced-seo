<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api;

/**
 * Repository interface for filter URL rewrite rules.
 */
interface FilterRewriteRepositoryInterface
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
