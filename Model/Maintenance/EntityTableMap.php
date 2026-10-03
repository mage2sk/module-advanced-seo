<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Maintenance;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Panth\AdvancedSEO\Api\MetaResolverInterface;

class EntityTableMap
{
    private const CHECK_ALIAS = 'panth_seo_exists';

    private const MAP = [
        MetaResolverInterface::ENTITY_PRODUCT  => ['catalog_product_entity', 'entity_id'],
        MetaResolverInterface::ENTITY_CATEGORY => ['catalog_category_entity', 'entity_id'],
        MetaResolverInterface::ENTITY_CMS      => ['cms_page', 'page_id'],
        'cms_page'                             => ['cms_page', 'page_id'],
    ];

    private const ALIASES = [
        MetaResolverInterface::ENTITY_CMS => [MetaResolverInterface::ENTITY_CMS, 'cms_page'],
        'cms_page'                        => [MetaResolverInterface::ENTITY_CMS, 'cms_page'],
    ];

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function isMapped(string $entityType): bool
    {
        return isset(self::MAP[$entityType]);
    }

    public function mappedTypes(): array
    {
        return array_keys(self::MAP);
    }

    public function sweepTypes(): array
    {
        return [
            MetaResolverInterface::ENTITY_PRODUCT,
            MetaResolverInterface::ENTITY_CATEGORY,
            MetaResolverInterface::ENTITY_CMS,
        ];
    }

    public function aliasesFor(string $entityType): array
    {
        return self::ALIASES[$entityType] ?? [$entityType];
    }

    public function tableFor(string $entityType): ?string
    {
        if (!isset(self::MAP[$entityType])) {
            return null;
        }

        return $this->resource->getTableName(self::MAP[$entityType][0]);
    }

    public function primaryKeyFor(string $entityType): ?string
    {
        return self::MAP[$entityType][1] ?? null;
    }

    public function existingIds(string $entityType, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $table = $this->tableFor($entityType);
        $key   = $this->primaryKeyFor($entityType);
        if ($table === null || $key === null) {
            return $ids;
        }

        $connection = $this->resource->getConnection();
        $found = $connection->fetchCol(
            $connection->select()->from($table, [$key])->where($key . ' IN (?)', $ids)
        );

        return array_values(array_intersect($ids, array_map('intval', $found)));
    }

    public function joinExisting(Select $select, string $entityType, string $alias, string $idColumn): bool
    {
        $table = $this->tableFor($entityType);
        $key   = $this->primaryKeyFor($entityType);
        if ($table === null || $key === null) {
            return false;
        }

        $connection = $this->resource->getConnection();
        $select->joinInner(
            [self::CHECK_ALIAS => $table],
            sprintf(
                '%s = %s',
                $connection->quoteIdentifier(self::CHECK_ALIAS . '.' . $key),
                $connection->quoteIdentifier($alias . '.' . $idColumn)
            ),
            []
        );

        return true;
    }

    public function existsCondition(string $alias, string $typeColumn, string $idColumn): string
    {
        $connection = $this->resource->getConnection();
        $type = $connection->quoteIdentifier($alias . '.' . $typeColumn);
        $id   = $connection->quoteIdentifier($alias . '.' . $idColumn);

        $clauses = [];
        foreach (self::MAP as $entityType => [$table, $key]) {
            $clauses[] = sprintf(
                '(%s = %s AND EXISTS (SELECT 1 FROM %s AS %s WHERE %s = %s))',
                $type,
                $connection->quote($entityType),
                $connection->quoteIdentifier($this->resource->getTableName($table)),
                $connection->quoteIdentifier(self::CHECK_ALIAS),
                $connection->quoteIdentifier(self::CHECK_ALIAS . '.' . $key),
                $id
            );
        }

        $clauses[] = sprintf(
            '%s NOT IN (%s)',
            $type,
            implode(', ', array_map(
                static fn (string $entityType): string => $connection->quote($entityType),
                array_keys(self::MAP)
            ))
        );

        return '(' . implode(' OR ', $clauses) . ')';
    }

    public function orphanIdsSelect(
        string $table,
        string $primaryKey,
        string $typeColumn,
        string $idColumn,
        string $entityType,
        int $limit
    ): ?Select {
        $select = $this->orphanSelect($table, $primaryKey, $typeColumn, $idColumn, $entityType);

        return $select?->limit($limit);
    }

    public function orphanCountSelect(
        string $table,
        string $typeColumn,
        string $idColumn,
        string $entityType
    ): ?Select {
        return $this->orphanSelect($table, new \Zend_Db_Expr('COUNT(*)'), $typeColumn, $idColumn, $entityType);
    }

    private function orphanSelect(
        string $table,
        string|\Zend_Db_Expr $columns,
        string $typeColumn,
        string $idColumn,
        string $entityType
    ): ?Select {
        $entityTable = $this->tableFor($entityType);
        $entityKey   = $this->primaryKeyFor($entityType);
        if ($entityTable === null || $entityKey === null) {
            return null;
        }

        $connection = $this->resource->getConnection();

        return $connection->select()
            ->from(['target' => $table], $columns)
            ->joinLeft(
                ['entity' => $entityTable],
                sprintf(
                    '%s = %s',
                    $connection->quoteIdentifier('entity.' . $entityKey),
                    $connection->quoteIdentifier('target.' . $idColumn)
                ),
                []
            )
            ->where($connection->quoteIdentifier('target.' . $typeColumn) . ' IN (?)', $this->aliasesFor($entityType))
            ->where($connection->quoteIdentifier('target.' . $idColumn) . ' IS NOT NULL')
            ->where($connection->quoteIdentifier('entity.' . $entityKey) . ' IS NULL');
    }
}
