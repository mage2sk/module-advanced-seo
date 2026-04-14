<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the `in_xml_sitemap` EAV attribute for both catalog_product and
 * catalog_category entities, allowing merchants to exclude individual
 * products or categories from the generated XML sitemap.
 *
 * Attribute details:
 *  - Type: int (boolean)
 *  - Default: 1 (included in sitemap)
 *  - Group: "Search Engine Optimization"
 *  - Visible in admin forms
 */
class AddSitemapExclusionAttributes implements DataPatchInterface
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

        // ── Product attribute ───────────────────────────────────────
        if (!$eavSetup->getAttributeId(Product::ENTITY, 'in_xml_sitemap')) {
            $eavSetup->addAttribute(
                Product::ENTITY,
                'in_xml_sitemap',
                [
                    'type'                    => 'int',
                    'label'                   => 'Include in XML Sitemap',
                    'input'                   => 'boolean',
                    'source'                  => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::class,
                    'default'                 => '1',
                    'required'                => false,
                    'global'                  => ScopedAttributeInterface::SCOPE_STORE,
                    'group'                   => 'Search Engine Optimization',
                    'sort_order'              => 200,
                    'visible'                 => true,
                    'user_defined'            => false,
                    'searchable'              => false,
                    'filterable'              => false,
                    'comparable'              => false,
                    'visible_on_front'        => false,
                    'used_in_product_listing'  => false,
                    'is_used_in_grid'         => true,
                    'is_visible_in_grid'      => false,
                    'is_filterable_in_grid'   => true,
                    'apply_to'                => 'simple,configurable,virtual,bundle,grouped,downloadable',
                ]
            );
        }

        // Add product in_xml_sitemap to ALL product attribute sets
        $this->addProductAttributeToAllSets($eavSetup, 'in_xml_sitemap');

        // ── Category attribute ──────────────────────────────────────
        if (!$eavSetup->getAttributeId(Category::ENTITY, 'in_xml_sitemap')) {
            $eavSetup->addAttribute(
                Category::ENTITY,
                'in_xml_sitemap',
                [
                    'type'                    => 'int',
                    'label'                   => 'Include in XML Sitemap',
                    'input'                   => 'boolean',
                    'source'                  => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::class,
                    'default'                 => '1',
                    'required'                => false,
                    'global'                  => ScopedAttributeInterface::SCOPE_STORE,
                    'group'                   => 'Search Engine Optimization',
                    'sort_order'              => 200,
                    'visible'                 => true,
                    'user_defined'            => false,
                ]
            );
        }

        // Add category in_xml_sitemap to ALL category attribute sets
        $this->addCategoryAttributeToAllSets($eavSetup, 'in_xml_sitemap');

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * Assign a product attribute to ALL existing attribute sets under the
     * "Search Engine Optimization" group (falls back to the default group).
     */
    private function addProductAttributeToAllSets(EavSetup $eavSetup, string $attributeCode): void
    {
        $entityTypeId   = $eavSetup->getEntityTypeId(Product::ENTITY);
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
