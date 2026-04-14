<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Inserts a default "Main Sitemap" profile with all entity types enabled.
 */
class AddDefaultSitemapProfile implements DataPatchInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime
    ) {
    }

    public static function getDependencies(): array
    {
        return [
            \Panth\AdvancedSEO\Setup\Patch\Schema\AddSitemapProfileTable::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_sitemap_profile');

        // Check if a profile already exists
        $existing = $conn->fetchOne(
            $conn->select()->from($table, ['profile_id'])->limit(1)
        );
        if ($existing) {
            return $this;
        }

        $now = $this->dateTime->gmtDate();

        $conn->insert($table, [
            'name'                => 'Main Sitemap',
            'store_id'            => 0,
            'entity_types'        => 'product,category,cms,custom',
            'include_images'      => 1,
            'include_video'       => 0,
            'include_hreflang'    => 0,
            'max_urls_per_file'   => 50000,
            'changefreq_product'  => 'weekly',
            'changefreq_category' => 'weekly',
            'changefreq_cms'      => 'monthly',
            'priority_product'    => 0.8,
            'priority_category'   => 0.6,
            'priority_cms'        => 0.5,
            'priority_homepage'   => 1.0,
            'exclude_out_of_stock' => 0,
            'exclude_noindex'     => 1,
            'custom_links'        => '',
            'output_path'         => 'sitemap/panth/{store_code}/',
            'url_count'           => 0,
            'file_count'          => 0,
            'is_active'           => 1,
            'cron_enabled'        => 0,
            'cron_schedule'       => '0 2 * * *',
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        return $this;
    }
}
