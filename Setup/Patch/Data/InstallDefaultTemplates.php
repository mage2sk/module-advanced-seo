<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Installs baseline meta templates for product, category and CMS entities
 * at the default store scope so the module works out of the box.
 */
class InstallDefaultTemplates implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('panth_seo_template');

        $rows = [
            [
                'store_id'         => 0,
                'entity_type'      => 'product',
                'scope'            => 'default',
                'name'             => 'Default Product Template',
                'meta_title'       => '{{name}} - {{brand}} | {{store.name}}',
                'meta_description' => 'Buy {{name}} online. {{short_description|truncate:155}}',
                'meta_keywords'    => '{{name}}, {{brand}}, {{category.name}}',
                'og_title'         => '{{name}}',
                'og_description'   => '{{short_description|truncate:200}}',
                'og_image'         => '{{image}}',
                'twitter_card'     => 'summary_large_image',
                'robots'           => 'index,follow',
                'priority'         => 10,
                'is_active'        => 1,
            ],
            [
                'store_id'         => 0,
                'entity_type'      => 'category',
                'scope'            => 'default',
                'name'             => 'Default Category Template',
                'meta_title'       => '{{name}} | {{store.name}}',
                'meta_description' => 'Shop our selection of {{name}}. {{description|truncate:140}}',
                'meta_keywords'    => '{{name}}, {{parent.name}}',
                'og_title'         => '{{name}}',
                'og_description'   => '{{description|truncate:200}}',
                'og_image'         => '{{image}}',
                'twitter_card'     => 'summary',
                'robots'           => 'index,follow',
                'priority'         => 10,
                'is_active'        => 1,
            ],
            [
                'store_id'         => 0,
                'entity_type'      => 'cms',
                'scope'            => 'default',
                'name'             => 'Default CMS Template',
                // {{page}} resolves to the CMS page title (see Token\PageToken).
                // Earlier iterations of this patch used {{title}} which had no
                // matching token resolver, so the indexer rendered blank
                // titles and the storefront fell through to the native
                // cms_page.meta_title — which on multi-store setups can still
                // contain literal "Default Store View" text from the sample
                // data seed.
                'meta_title'       => '{{page}} | {{store.name}}',
                'meta_description' => '{{content_heading|truncate:160}}',
                'meta_keywords'    => null,
                'og_title'         => '{{page}}',
                'og_description'   => '{{content_heading|truncate:200}}',
                'og_image'         => null,
                'twitter_card'     => 'summary',
                'robots'           => 'index,follow',
                'priority'         => 10,
                'is_active'        => 1,
            ],
        ];

        foreach ($rows as $row) {
            $existing = $connection->fetchOne(
                $connection->select()
                    ->from($table, 'template_id')
                    ->where('store_id = ?', $row['store_id'])
                    ->where('entity_type = ?', $row['entity_type'])
                    ->where('scope = ?', $row['scope'])
                    ->limit(1)
            );
            if (!$existing) {
                $connection->insert($table, $row);
            }
        }

        $this->moduleDataSetup->endSetup();
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
