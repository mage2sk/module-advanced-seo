<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Adds panth_seo_related table for persisted internal-link suggestions.
 * (We intentionally do NOT persist the full link graph — it is computed
 * in-memory and cached. Only the top suggestions are written here.)
 */
class AddLinkGraphTable implements SchemaPatchInterface
{
    public const TABLE = 'panth_seo_related';

    public function __construct(
        private readonly SchemaSetupInterface $schemaSetup
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $this->schemaSetup->startSetup();
        $conn = $this->schemaSetup->getConnection();
        $table = $this->schemaSetup->getTable(self::TABLE);

        if (!$conn->isTableExists($table)) {
            $definition = $conn->newTable($table)
                ->setComment('Panth SEO related / internal linking suggestions')
                ->addColumn('related_id', Table::TYPE_INTEGER, null, [
                    'identity' => true,
                    'unsigned' => true,
                    'nullable' => false,
                    'primary'  => true,
                ], 'Related ID')
                ->addColumn('source_type', Table::TYPE_TEXT, 32, ['nullable' => false], 'Source entity type')
                ->addColumn('source_id', Table::TYPE_INTEGER, null, [
                    'unsigned' => true,
                    'nullable' => false,
                ], 'Source entity ID')
                ->addColumn('target_type', Table::TYPE_TEXT, 32, ['nullable' => false], 'Target entity type')
                ->addColumn('target_id', Table::TYPE_INTEGER, null, [
                    'unsigned' => true,
                    'nullable' => false,
                ], 'Target entity ID')
                ->addColumn('score', Table::TYPE_DECIMAL, '12,6', ['nullable' => false, 'default' => '0'], 'Score')
                ->addColumn('store_id', Table::TYPE_SMALLINT, null, [
                    'unsigned' => true,
                    'nullable' => false,
                    'default'  => 0,
                ], 'Store ID')
                ->addColumn('updated_at', Table::TYPE_TIMESTAMP, null, [
                    'nullable' => false,
                    'default'  => Table::TIMESTAMP_INIT_UPDATE,
                ], 'Updated at')
                ->addIndex(
                    $this->schemaSetup->getIdxName(self::TABLE, ['source_type', 'source_id', 'store_id']),
                    ['source_type', 'source_id', 'store_id']
                )
                ->addIndex(
                    $this->schemaSetup->getIdxName(self::TABLE, ['target_type', 'target_id']),
                    ['target_type', 'target_id']
                );
            $conn->createTable($definition);
        }

        $this->schemaSetup->endSetup();
        return $this;
    }
}
