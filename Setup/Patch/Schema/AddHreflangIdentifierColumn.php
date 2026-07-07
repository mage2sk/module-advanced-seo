<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class AddHreflangIdentifierColumn implements SchemaPatchInterface
{
    public function __construct(
        private readonly SchemaSetupInterface $schemaSetup
    ) {
    }

    public function apply(): self
    {
        $this->schemaSetup->startSetup();

        $connection = $this->schemaSetup->getConnection();
        $table      = $this->schemaSetup->getTable('panth_seo_override');

        if (!$connection->tableColumnExists($table, 'hreflang_identifier')) {
            $connection->addColumn(
                $table,
                'hreflang_identifier',
                [
                    'type'     => Table::TYPE_TEXT,
                    'length'   => 255,
                    'nullable' => true,
                    'default'  => null,
                    'comment'  => 'Hreflang identifier for cross-store linking',
                ]
            );
        }

        $this->schemaSetup->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
