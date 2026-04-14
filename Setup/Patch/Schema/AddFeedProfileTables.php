<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Creates panth_seo_feed_profile and panth_seo_feed_field tables
 * for the configurable product feed system.
 */
class AddFeedProfileTables implements SchemaPatchInterface
{
    public const TABLE_PROFILE = 'panth_seo_feed_profile';
    public const TABLE_FIELD = 'panth_seo_feed_field';

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

        // ── panth_seo_feed_profile ──
        $profileTable = $this->schemaSetup->getTable(self::TABLE_PROFILE);
        if (!$conn->isTableExists($profileTable)) {
            $definition = $conn->newTable($profileTable)
                ->setComment('Panth SEO Feed Profiles')
                ->addColumn('feed_id', Table::TYPE_INTEGER, null, [
                    'identity' => true,
                    'unsigned' => true,
                    'nullable' => false,
                    'primary'  => true,
                ], 'Feed Profile ID')
                ->addColumn('name', Table::TYPE_TEXT, 255, [
                    'nullable' => false,
                ], 'Profile Name')
                ->addColumn('feed_type', Table::TYPE_TEXT, 32, [
                    'nullable' => false,
                    'default'  => 'google_shopping',
                ], 'Feed Type')
                ->addColumn('store_id', Table::TYPE_SMALLINT, null, [
                    'unsigned' => true,
                    'nullable' => false,
                    'default'  => 1,
                ], 'Store ID')
                ->addColumn('filename', Table::TYPE_TEXT, 255, [
                    'nullable' => false,
                    'default'  => 'google_feed.xml',
                ], 'Output Filename')
                ->addColumn('output_format', Table::TYPE_TEXT, 16, [
                    'nullable' => false,
                    'default'  => 'xml',
                ], 'Output Format (xml or csv)')
                ->addColumn('is_active', Table::TYPE_SMALLINT, null, [
                    'unsigned' => true,
                    'nullable' => false,
                    'default'  => 1,
                ], 'Is Active')
                ->addColumn('field_mapping', Table::TYPE_TEXT, Table::MAX_TEXT_SIZE, [
                    'nullable' => true,
                ], 'Field Mapping JSON')
                ->addColumn('conditions_serialized', Table::TYPE_TEXT, Table::MAX_TEXT_SIZE, [
                    'nullable' => true,
                ], 'Product Filter Conditions JSON')
                ->addColumn('include_out_of_stock', Table::TYPE_SMALLINT, null, [
                    'unsigned' => true,
                    'nullable' => false,
                    'default'  => 0,
                ], 'Include Out of Stock')
                ->addColumn('include_disabled', Table::TYPE_SMALLINT, null, [
                    'unsigned' => true,
                    'nullable' => false,
                    'default'  => 0,
                ], 'Include Disabled Products')
                ->addColumn('include_not_visible', Table::TYPE_SMALLINT, null, [
                    'unsigned' => true,
                    'nullable' => false,
                    'default'  => 0,
                ], 'Include Not Visible Individually')
                ->addColumn('category_filter', Table::TYPE_TEXT, 65536, [
                    'nullable' => true,
                ], 'Category IDs Filter (comma-separated)')
                ->addColumn('attribute_set_filter', Table::TYPE_TEXT, 65536, [
                    'nullable' => true,
                ], 'Attribute Set IDs Filter (comma-separated)')
                ->addColumn('delivery_country', Table::TYPE_TEXT, 2, [
                    'nullable' => true,
                    'default'  => 'US',
                ], 'Delivery Country Code')
                ->addColumn('currency', Table::TYPE_TEXT, 3, [
                    'nullable' => true,
                    'default'  => 'USD',
                ], 'Currency Code')
                ->addColumn('cron_enabled', Table::TYPE_SMALLINT, null, [
                    'unsigned' => true,
                    'nullable' => false,
                    'default'  => 0,
                ], 'Cron Enabled')
                ->addColumn('cron_schedule', Table::TYPE_TEXT, 64, [
                    'nullable' => true,
                    'default'  => '0 1 * * *',
                ], 'Cron Schedule Expression')
                ->addColumn('last_generated_at', Table::TYPE_TIMESTAMP, null, [
                    'nullable' => true,
                ], 'Last Generated At')
                ->addColumn('generation_time', Table::TYPE_INTEGER, null, [
                    'unsigned' => true,
                    'nullable' => true,
                ], 'Generation Time in Seconds')
                ->addColumn('product_count', Table::TYPE_INTEGER, null, [
                    'unsigned' => true,
                    'nullable' => false,
                    'default'  => 0,
                ], 'Product Count in Feed')
                ->addColumn('file_size', Table::TYPE_INTEGER, null, [
                    'unsigned' => true,
                    'nullable' => false,
                    'default'  => 0,
                ], 'File Size in Bytes')
                ->addColumn('file_url', Table::TYPE_TEXT, 512, [
                    'nullable' => true,
                ], 'Generated File URL')
                ->addColumn('created_at', Table::TYPE_TIMESTAMP, null, [
                    'nullable' => false,
                    'default'  => Table::TIMESTAMP_INIT,
                ], 'Created At')
                ->addColumn('updated_at', Table::TYPE_TIMESTAMP, null, [
                    'nullable' => false,
                    'default'  => Table::TIMESTAMP_INIT_UPDATE,
                ], 'Updated At');
            $conn->createTable($definition);
        }

        // ── panth_seo_feed_field ──
        $fieldTable = $this->schemaSetup->getTable(self::TABLE_FIELD);
        if (!$conn->isTableExists($fieldTable)) {
            $definition = $conn->newTable($fieldTable)
                ->setComment('Panth SEO Feed Field Mapping')
                ->addColumn('field_id', Table::TYPE_INTEGER, null, [
                    'identity' => true,
                    'unsigned' => true,
                    'nullable' => false,
                    'primary'  => true,
                ], 'Field ID')
                ->addColumn('feed_id', Table::TYPE_INTEGER, null, [
                    'unsigned' => true,
                    'nullable' => false,
                ], 'Feed Profile ID')
                ->addColumn('feed_field', Table::TYPE_TEXT, 255, [
                    'nullable' => false,
                ], 'Output Feed Field Name')
                ->addColumn('source_type', Table::TYPE_TEXT, 32, [
                    'nullable' => false,
                    'default'  => 'attribute',
                ], 'Source Type')
                ->addColumn('source_value', Table::TYPE_TEXT, 512, [
                    'nullable' => false,
                ], 'Source Value')
                ->addColumn('default_value', Table::TYPE_TEXT, 512, [
                    'nullable' => true,
                ], 'Default Value')
                ->addColumn('sort_order', Table::TYPE_INTEGER, null, [
                    'unsigned' => true,
                    'nullable' => false,
                    'default'  => 0,
                ], 'Sort Order')
                ->addColumn('is_required', Table::TYPE_SMALLINT, null, [
                    'unsigned' => true,
                    'nullable' => false,
                    'default'  => 0,
                ], 'Is Required')
                ->addIndex(
                    $this->schemaSetup->getIdxName(self::TABLE_FIELD, ['feed_id']),
                    ['feed_id']
                )
                ->addForeignKey(
                    $this->schemaSetup->getFkName(self::TABLE_FIELD, 'feed_id', self::TABLE_PROFILE, 'feed_id'),
                    'feed_id',
                    $profileTable,
                    'feed_id',
                    Table::ACTION_CASCADE
                );
            $conn->createTable($definition);
        }

        $this->schemaSetup->endSetup();
        return $this;
    }
}
