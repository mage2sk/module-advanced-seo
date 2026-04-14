<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds `layered_navigation_canonical` to `catalog_eav_attribute`.
 *
 * This column stores a per-attribute override for canonical URL behavior
 * when that attribute is used as a layered navigation filter. Allowed
 * values come from {@see \Panth\AdvancedSEO\Model\Config\Source\LayeredNavCanonical}:
 *
 *  - use_global : defer to the global/store-level canonical setting (default).
 *  - category   : canonical always points to the unfiltered base category URL.
 *  - filtered   : canonical points to the filtered page URL.
 *  - noindex    : emit a NOINDEX directive instead of a canonical tag.
 *
 * The column is added directly to `catalog_eav_attribute` (the additional
 * properties table for catalog attributes), making it editable in the
 * attribute edit form under "Storefront Properties".
 */
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

        // The DDL column creation is handled by the schema patch
        // AddCanonicalBehaviorColumn (runs before data patches, outside a transaction).

        // Register the column as an additional EAV attribute property so
        // Magento populates it in the attribute edit form.
        /** @var EavSetup $eavSetup */
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

        // Add layered_navigation_canonical to ALL product attribute sets
        $this->addProductAttributeToAllSets($eavSetup, 'layered_navigation_canonical');

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * Assign a product attribute to ALL existing attribute sets under the
     * "Storefront Properties" group (falls back to the default group).
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
                    'Storefront Properties'
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
        return [
            \Panth\AdvancedSEO\Setup\Patch\Schema\AddCanonicalBehaviorColumn::class,
        ];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
