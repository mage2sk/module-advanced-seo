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
use Panth\AdvancedSEO\Model\Config\Source\MetaRobots;

class AddMetaRobotsAttributes implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $commonConfig = [
            'type'                    => 'varchar',
            'label'                   => 'Meta Robots',
            'input'                   => 'select',
            'source'                  => MetaRobots::class,
            'default'                 => null,
            'required'                => false,
            'global'                  => ScopedAttributeInterface::SCOPE_STORE,
            'group'                   => 'Search Engine Optimization',
            'sort_order'              => 65,
            'visible'                 => true,
            'user_defined'            => false,
            'note'                    => 'Leave empty to use the setting from templates or rules.',
        ];

        if (!$eavSetup->getAttributeId(Product::ENTITY, 'meta_robots')) {
            $eavSetup->addAttribute(
                Product::ENTITY,
                'meta_robots',
                array_merge($commonConfig, [
                    'searchable'              => false,
                    'filterable'              => false,
                    'comparable'              => false,
                    'visible_on_front'        => false,
                    'used_in_product_listing'  => false,
                    'is_used_in_grid'         => true,
                    'is_visible_in_grid'      => false,
                    'is_filterable_in_grid'   => true,
                    'apply_to'                => 'simple,configurable,virtual,bundle,grouped,downloadable',
                ])
            );
        }

        $this->addProductAttributeToAllSets($eavSetup, 'meta_robots');

        if (!$eavSetup->getAttributeId(Category::ENTITY, 'meta_robots')) {
            $eavSetup->addAttribute(
                Category::ENTITY,
                'meta_robots',
                $commonConfig
            );
        }

        $this->addCategoryAttributeToAllSets($eavSetup, 'meta_robots');

        $this->moduleDataSetup->endSetup();

        return $this;
    }

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

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
