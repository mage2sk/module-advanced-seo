<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Returns all product attribute sets as option array for multiselect.
 */
class AttributeSetOptions implements OptionSourceInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly EavConfig $eavConfig
    ) {
    }

    public function toOptionArray(): array
    {
        $entityTypeId = (int) $this->eavConfig->getEntityType(Product::ENTITY)->getId();

        $collection = $this->collectionFactory->create();
        $collection->setEntityTypeFilter($entityTypeId);
        $collection->setOrder('attribute_set_name', 'ASC');

        $options = [];
        foreach ($collection as $set) {
            $options[] = [
                'value' => $set->getId(),
                'label' => $set->getAttributeSetName(),
            ];
        }

        return $options;
    }
}
