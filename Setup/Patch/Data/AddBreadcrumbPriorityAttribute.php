<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the `breadcrumbs_priority` EAV attribute on the catalog_category
 * entity. Merchants assign a numeric weight to each category; the breadcrumb
 * plugin uses this weight to choose the optimal category path for products
 * that belong to multiple categories.
 *
 * Attribute details:
 *  - Type: int
 *  - Input: text
 *  - Default: 0
 *  - Group: "Search Engine Optimization"
 *  - Sort order: 70
 */
class AddBreadcrumbPriorityAttribute implements DataPatchInterface
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

        if (!$eavSetup->getAttributeId(Category::ENTITY, 'breadcrumbs_priority')) {
            $eavSetup->addAttribute(
                Category::ENTITY,
                'breadcrumbs_priority',
                [
                    'type'         => 'int',
                    'label'        => 'Breadcrumb Priority',
                    'input'        => 'text',
                    'default'      => 0,
                    'required'     => false,
                    'global'       => ScopedAttributeInterface::SCOPE_STORE,
                    'group'        => 'Search Engine Optimization',
                    'sort_order'   => 70,
                    'visible'      => true,
                    'user_defined' => false,
                ]
            );
        }

        // Add breadcrumbs_priority to ALL category attribute sets
        $this->addCategoryAttributeToAllSets($eavSetup, 'breadcrumbs_priority');

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
