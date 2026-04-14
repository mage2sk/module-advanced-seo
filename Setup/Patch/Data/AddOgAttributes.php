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

class AddOgAttributes implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $ogAttributes = [
            'og_title' => ['label' => 'OG Title', 'type' => 'varchar', 'input' => 'text', 'sort' => 60],
            'og_description' => ['label' => 'OG Description', 'type' => 'text', 'input' => 'textarea', 'sort' => 65],
            'og_image' => ['label' => 'OG Image URL', 'type' => 'varchar', 'input' => 'text', 'sort' => 70],
        ];

        // Product OG attributes
        foreach ($ogAttributes as $code => $config) {
            if (!$eavSetup->getAttributeId(Product::ENTITY, $code)) {
                $eavSetup->addAttribute(Product::ENTITY, $code, [
                    'type' => $config['type'],
                    'label' => $config['label'],
                    'input' => $config['input'],
                    'required' => false,
                    'global' => ScopedAttributeInterface::SCOPE_STORE,
                    'visible' => true,
                    'user_defined' => false,
                    'searchable' => false,
                    'filterable' => false,
                    'comparable' => false,
                    'visible_on_front' => false,
                    'used_in_product_listing' => false,
                    'sort_order' => $config['sort'],
                    'group' => 'Search Engine Optimization',
                ]);
            }
        }

        // Assign product OG attributes to ALL attribute sets
        $productEntityTypeId = $eavSetup->getEntityTypeId(Product::ENTITY);
        $conn = $this->moduleDataSetup->getConnection();
        $attrSets = $conn->fetchCol(
            'SELECT attribute_set_id FROM eav_attribute_set WHERE entity_type_id = ?',
            [$productEntityTypeId]
        );

        $seoGroupName = 'Search Engine Optimization';
        foreach ($attrSets as $setId) {
            $groupId = $eavSetup->getAttributeGroupId($productEntityTypeId, $setId, $seoGroupName);
            if (!$groupId) {
                $eavSetup->addAttributeGroup($productEntityTypeId, $setId, $seoGroupName, 65);
                $groupId = $eavSetup->getAttributeGroupId($productEntityTypeId, $setId, $seoGroupName);
            }

            foreach (array_keys($ogAttributes) as $attrCode) {
                $attrId = $eavSetup->getAttributeId($productEntityTypeId, $attrCode);
                if ($attrId) {
                    $eavSetup->addAttributeToGroup($productEntityTypeId, $setId, $groupId, $attrId);
                }
            }

            // Also assign seo_name to all sets
            $seoNameId = $eavSetup->getAttributeId($productEntityTypeId, 'seo_name');
            if ($seoNameId) {
                $eavSetup->addAttributeToGroup($productEntityTypeId, $setId, $groupId, $seoNameId);
            }
        }

        // Category OG attributes
        foreach ($ogAttributes as $code => $config) {
            if (!$eavSetup->getAttributeId(Category::ENTITY, $code)) {
                $eavSetup->addAttribute(Category::ENTITY, $code, [
                    'type' => $config['type'],
                    'label' => $config['label'],
                    'input' => $config['input'],
                    'required' => false,
                    'global' => ScopedAttributeInterface::SCOPE_STORE,
                    'visible' => true,
                    'sort_order' => $config['sort'],
                    'group' => 'Search Engine Optimization',
                ]);
            }
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddSeoNameAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
