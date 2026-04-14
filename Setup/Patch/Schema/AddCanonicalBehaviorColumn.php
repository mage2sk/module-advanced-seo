<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Adds the `layered_navigation_canonical` column to `catalog_eav_attribute`.
 *
 * DDL statements cannot run inside the transaction that Magento wraps around
 * data patches, so this column creation lives in a schema patch (which runs
 * outside of a transaction).
 */
class AddCanonicalBehaviorColumn implements SchemaPatchInterface
{
    public function __construct(
        private readonly SchemaSetupInterface $schemaSetup
    ) {
    }

    public function apply(): self
    {
        $this->schemaSetup->startSetup();

        $connection = $this->schemaSetup->getConnection();
        $table      = $this->schemaSetup->getTable('catalog_eav_attribute');

        if (!$connection->tableColumnExists($table, 'layered_navigation_canonical')) {
            $connection->addColumn(
                $table,
                'layered_navigation_canonical',
                [
                    'type'     => Table::TYPE_TEXT,
                    'length'   => 32,
                    'nullable' => false,
                    'default'  => 'use_global',
                    'comment'  => 'Canonical behavior for layered nav pages using this attribute',
                ]
            );
        }

        $this->schemaSetup->endSetup();

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
