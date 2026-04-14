<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates two EAV attributes on the `catalog_category` entity:
 *
 * 1. `seo_name`                – SEO-friendly category name that can differ
 *                                 from the visible category name.
 * 2. `exclude_from_html_sitemap` – boolean flag to hide a category from the
 *                                  HTML sitemap page.
 *
 * Both attributes are store-scoped and placed in the
 * "Search Engine Optimization" attribute group.
 */
class AddCategorySeoAttributes implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        // ── seo_name ───────────────────────────────────────────────────
        if (!$eavSetup->getAttributeId(Category::ENTITY, 'seo_name')) {
            $eavSetup->addAttribute(
                Category::ENTITY,
                'seo_name',
                [
                    'type'       => 'varchar',
                    'label'      => 'SEO Name',
                    'input'      => 'text',
                    'required'   => false,
                    'visible'    => true,
                    'global'     => ScopedAttributeInterface::SCOPE_STORE,
                    'group'      => 'Search Engine Optimization',
                    'sort_order' => 55,
                    'user_defined' => true,
                    'note'       => 'If set, used in meta-title/description templates instead of the category name.',
                ]
            );
        }

        // Add category seo_name to ALL category attribute sets
        $this->addCategoryAttributeToAllSets($eavSetup, 'seo_name');

        // ── exclude_from_html_sitemap ──────────────────────────────────
        if (!$eavSetup->getAttributeId(Category::ENTITY, 'exclude_from_html_sitemap')) {
            $eavSetup->addAttribute(
                Category::ENTITY,
                'exclude_from_html_sitemap',
                [
                    'type'       => 'int',
                    'label'      => 'Exclude from HTML Sitemap',
                    'input'      => 'boolean',
                    'source'     => Boolean::class,
                    'default'    => '0',
                    'required'   => false,
                    'visible'    => true,
                    'global'     => ScopedAttributeInterface::SCOPE_STORE,
                    'group'      => 'Search Engine Optimization',
                    'sort_order' => 210,
                    'user_defined' => false,
                ]
            );
        }

        // Add category exclude_from_html_sitemap to ALL category attribute sets
        $this->addCategoryAttributeToAllSets($eavSetup, 'exclude_from_html_sitemap');

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * Assign a category attribute to ALL existing attribute sets under the
     * "Search Engine Optimization" group (falls back to the default group).
     */
    private function addCategoryAttributeToAllSets(EavSetup $eavSetup, string $attributeCode): void
    {
        $entityTypeId   = $eavSetup->getEntityTypeId(Category::ENTITY);
        $attributeSets  = $eavSetup->getAllAttributeSetIds($entityTypeId);

        foreach ($attributeSets as $attributeSetId) {
            try {
                $groupId = $eavSetup->getAttributeGroupId(
                    $entityTypeId,
                    $attributeSetId,
                    'Search Engine Optimization'
                );
            } catch (\Exception $e) {
                $groupId = $eavSetup->getDefaultAttributeGroupId($entityTypeId, $attributeSetId);
            }
            $eavSetup->addAttributeToSet($entityTypeId, $attributeSetId, $groupId, $attributeCode);
        }
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
