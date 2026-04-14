<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Inserts a default Google Shopping feed profile with standard field mappings.
 */
class AddDefaultGoogleShoppingFeed implements DataPatchInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime
    ) {
    }

    public static function getDependencies(): array
    {
        return [
            \Panth\AdvancedSEO\Setup\Patch\Schema\AddFeedProfileTables::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $conn = $this->resource->getConnection();
        $profileTable = $this->resource->getTableName('panth_seo_feed_profile');
        $fieldTable = $this->resource->getTableName('panth_seo_feed_field');

        // Check if a profile already exists
        $existing = $conn->fetchOne(
            $conn->select()->from($profileTable, ['feed_id'])->limit(1)
        );
        if ($existing) {
            return $this;
        }

        $now = $this->dateTime->gmtDate();

        $conn->insert($profileTable, [
            'name'              => 'Google Shopping Feed',
            'feed_type'         => 'google_shopping',
            'store_id'          => 1,
            'filename'          => 'google_feed.xml',
            'output_format'     => 'xml',
            'is_active'         => 1,
            'include_out_of_stock' => 0,
            'include_disabled'  => 0,
            'include_not_visible' => 0,
            'delivery_country'  => 'US',
            'currency'          => 'USD',
            'cron_enabled'      => 0,
            'cron_schedule'     => '0 1 * * *',
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $feedId = (int) $conn->lastInsertId($profileTable);

        $fields = [
            ['g:id',                       'attribute', 'sku',                       null,    10, 1],
            ['g:title',                    'attribute', 'name',                      null,    20, 1],
            ['g:description',              'attribute', 'description',               null,    30, 1],
            ['g:link',                     'template',  'product_url',               null,    40, 1],
            ['g:image_link',               'template',  'product_image_url',         null,    50, 1],
            ['g:price',                    'template',  'product_price',             null,    60, 1],
            ['g:sale_price',               'template',  'product_special_price',     null,    70, 0],
            ['g:availability',             'template',  'stock_status',              null,    80, 1],
            ['g:condition',                'static',    'new',                       null,    90, 0],
            ['g:brand',                    'attribute', 'manufacturer',              null,   100, 0],
            ['g:gtin',                     'attribute', 'gtin',                      null,   110, 0],
            ['g:mpn',                      'attribute', 'sku',                       null,   120, 0],
            ['g:product_type',             'template',  'category_path',             null,   130, 0],
            ['g:google_product_category',  'attribute', 'google_product_category',   null,   140, 0],
            ['g:shipping_weight',          'template',  'product_weight',            null,   150, 0],
            ['g:identifier_exists',        'static',    'false',                     null,   160, 0],
        ];

        foreach ($fields as [$feedField, $sourceType, $sourceValue, $defaultValue, $sortOrder, $isRequired]) {
            $conn->insert($fieldTable, [
                'feed_id'       => $feedId,
                'feed_field'    => $feedField,
                'source_type'   => $sourceType,
                'source_value'  => $sourceValue,
                'default_value' => $defaultValue,
                'sort_order'    => $sortOrder,
                'is_required'   => $isRequired,
            ]);
        }

        return $this;
    }
}
