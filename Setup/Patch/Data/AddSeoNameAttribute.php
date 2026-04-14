<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the `seo_name` EAV attribute on catalog_product.
 *
 * This attribute provides a dedicated SEO-friendly name that can differ from
 * the regular product name. Tokens like {{seo_name}} resolve this value with
 * a fallback to the standard product name.
 */
class AddSeoNameAttribute implements DataPatchInterface
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

        if (!$eavSetup->getAttributeId(Product::ENTITY, 'seo_name')) {
            $eavSetup->addAttribute(
                Product::ENTITY,
                'seo_name',
                [
                    'type'                    => 'varchar',
                    'label'                   => 'SEO Name',
                    'input'                   => 'text',
                    'required'                => false,
                    'visible'                 => true,
                    'global'                  => ScopedAttributeInterface::SCOPE_STORE,
                    'group'                   => 'Search Engine Optimization',
                    'searchable'              => false,
                    'comparable'              => false,
                    'used_in_product_listing'  => false,
                    'sort_order'              => 60,
                    'user_defined'            => true,
                    'is_used_in_grid'         => false,
                    'is_visible_in_grid'      => false,
                    'is_filterable_in_grid'   => false,
                    'apply_to'                => 'simple,configurable,virtual,bundle,grouped,downloadable',
                ]
            );
        }

        // Add seo_name to ALL product attribute sets
        $this->addProductAttributeToAllSets($eavSetup, 'seo_name');

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

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
