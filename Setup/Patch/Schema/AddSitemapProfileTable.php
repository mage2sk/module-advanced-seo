<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Creates the panth_seo_sitemap_profile table for configurable sitemap profiles.
 */
class AddSitemapProfileTable implements SchemaPatchInterface
{
    public const TABLE_NAME = 'panth_seo_sitemap_profile';

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
        $setup = $this->schemaSetup;
        $setup->startSetup();
        $conn = $setup->getConnection();

        if ($conn->isTableExists($setup->getTable(self::TABLE_NAME))) {
            $setup->endSetup();
            return $this;
        }

        $table = $conn->newTable($setup->getTable(self::TABLE_NAME))
            ->addColumn('profile_id', Table::TYPE_INTEGER, null, [
                'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
            ], 'Profile ID')
            ->addColumn('name', Table::TYPE_TEXT, 255, ['nullable' => false], 'Profile Name')
            ->addColumn('store_id', Table::TYPE_SMALLINT, null, [
                'unsigned' => true, 'nullable' => false, 'default' => 0,
            ], 'Store ID')
            ->addColumn('entity_types', Table::TYPE_TEXT, 512, [
                'nullable' => false, 'default' => 'product,category,cms',
            ], 'Comma-separated entity types')
            ->addColumn('include_images', Table::TYPE_SMALLINT, null, [
                'unsigned' => true, 'nullable' => false, 'default' => 1,
            ], 'Include product images')
            ->addColumn('include_video', Table::TYPE_SMALLINT, null, [
                'unsigned' => true, 'nullable' => false, 'default' => 0,
            ], 'Include video tags')
            ->addColumn('include_hreflang', Table::TYPE_SMALLINT, null, [
                'unsigned' => true, 'nullable' => false, 'default' => 0,
            ], 'Include hreflang tags')
            ->addColumn('max_urls_per_file', Table::TYPE_INTEGER, null, [
                'unsigned' => true, 'nullable' => false, 'default' => 50000,
            ], 'Max URLs per sitemap file')
            ->addColumn('changefreq_product', Table::TYPE_TEXT, 32, [
                'nullable' => false, 'default' => 'weekly',
            ], 'Product change frequency')
            ->addColumn('changefreq_category', Table::TYPE_TEXT, 32, [
                'nullable' => false, 'default' => 'weekly',
            ], 'Category change frequency')
            ->addColumn('changefreq_cms', Table::TYPE_TEXT, 32, [
                'nullable' => false, 'default' => 'monthly',
            ], 'CMS page change frequency')
            ->addColumn('priority_product', Table::TYPE_DECIMAL, '2,1', [
                'nullable' => false, 'default' => '0.8',
            ], 'Product priority')
            ->addColumn('priority_category', Table::TYPE_DECIMAL, '2,1', [
                'nullable' => false, 'default' => '0.6',
            ], 'Category priority')
            ->addColumn('priority_cms', Table::TYPE_DECIMAL, '2,1', [
                'nullable' => false, 'default' => '0.5',
            ], 'CMS page priority')
            ->addColumn('priority_homepage', Table::TYPE_DECIMAL, '2,1', [
                'nullable' => false, 'default' => '1.0',
            ], 'Homepage priority')
            ->addColumn('exclude_out_of_stock', Table::TYPE_SMALLINT, null, [
                'unsigned' => true, 'nullable' => false, 'default' => 0,
            ], 'Exclude out of stock products')
            ->addColumn('exclude_noindex', Table::TYPE_SMALLINT, null, [
                'unsigned' => true, 'nullable' => false, 'default' => 1,
            ], 'Exclude NOINDEX pages')
            ->addColumn('custom_links', Table::TYPE_TEXT, '64k', [
                'nullable' => true,
            ], 'JSON array of custom URLs')
            ->addColumn('output_path', Table::TYPE_TEXT, 512, [
                'nullable' => false, 'default' => 'sitemap/panth/{store_code}/',
            ], 'Output path relative to pub')
            ->addColumn('last_generated_at', Table::TYPE_TIMESTAMP, null, [
                'nullable' => true, 'default' => null,
            ], 'Last generation timestamp')
            ->addColumn('generation_time', Table::TYPE_INTEGER, null, [
                'unsigned' => true, 'nullable' => true,
            ], 'Last generation time in seconds')
            ->addColumn('url_count', Table::TYPE_INTEGER, null, [
                'unsigned' => true, 'nullable' => false, 'default' => 0,
            ], 'Total URL count')
            ->addColumn('file_count', Table::TYPE_INTEGER, null, [
                'unsigned' => true, 'nullable' => false, 'default' => 0,
            ], 'Generated file count')
            ->addColumn('is_active', Table::TYPE_SMALLINT, null, [
                'unsigned' => true, 'nullable' => false, 'default' => 1,
            ], 'Active')
            ->addColumn('cron_enabled', Table::TYPE_SMALLINT, null, [
                'unsigned' => true, 'nullable' => false, 'default' => 0,
            ], 'Enable cron auto-generation')
            ->addColumn('cron_schedule', Table::TYPE_TEXT, 64, [
                'nullable' => false, 'default' => '0 2 * * *',
            ], 'Cron schedule expression')
            ->addColumn('created_at', Table::TYPE_TIMESTAMP, null, [
                'nullable' => false, 'default' => Table::TIMESTAMP_INIT,
            ], 'Created at')
            ->addColumn('updated_at', Table::TYPE_TIMESTAMP, null, [
                'nullable' => false, 'default' => Table::TIMESTAMP_INIT_UPDATE,
            ], 'Updated at')
            ->addIndex(
                $setup->getIdxName(self::TABLE_NAME, ['is_active']),
                ['is_active']
            )
            ->addIndex(
                $setup->getIdxName(self::TABLE_NAME, ['store_id']),
                ['store_id']
            )
            ->addForeignKey(
                $setup->getFkName(self::TABLE_NAME, 'store_id', 'store', 'store_id'),
                'store_id',
                $setup->getTable('store'),
                'store_id',
                Table::ACTION_CASCADE
            )
            ->setComment('Panth SEO Sitemap Profiles');

        $conn->createTable($table);
        $setup->endSetup();

        return $this;
    }
}
