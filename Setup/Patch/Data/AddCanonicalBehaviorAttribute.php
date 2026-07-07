<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddCanonicalBehaviorAttribute implements DataPatchInterface
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

        $entityTypeId = $eavSetup->getEntityTypeId(Product::ENTITY);

        if (!$eavSetup->getAttributeId($entityTypeId, 'layered_navigation_canonical')) {
            $eavSetup->addAttribute(
                $entityTypeId,
                'layered_navigation_canonical',
                [
                    'type'                    => 'static',
                    'label'                   => 'Canonical for Layered Nav Pages',
                    'input'                   => 'select',
                    'source'                  => \Panth\AdvancedSEO\Model\Config\Source\LayeredNavCanonical::class,
                    'required'                => false,
                    'default'                 => 'use_global',
                    'visible'                 => false,
                    'user_defined'            => false,
                    'system'                  => true,
                    'group'                   => 'Storefront Properties',
                    'sort_order'              => 100,
                    'apply_to'                => '',
                ]
            );
        }

        $this->addProductAttributeToAllSets($eavSetup, 'layered_navigation_canonical');

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
                    'Storefront Properties'
                );
            } catch (\Exception $e) {
                $groupId = $eavSetup->getDefaultAttributeGroupId($entityTypeId, $attributeSetId);
            }
            $eavSetup->addAttributeToSet($entityTypeId, $attributeSetId, $groupId, $attributeCode);
        }
    }

    public static function getDependencies(): array
    {
        return [
            \Panth\AdvancedSEO\Setup\Patch\Schema\AddCanonicalBehaviorColumn::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }
}
