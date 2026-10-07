<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

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

        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

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

        $this->addCategoryAttributeToAllSets($eavSetup, 'seo_name');

        $this->moduleDataSetup->endSetup();

        return $this;
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
