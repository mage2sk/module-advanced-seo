<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Lists all filterable product attributes (select/multiselect used in layered nav).
 */
class FilterableAttributes implements OptionSourceInterface
{
    private ?array $options = null;

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function toOptionArray(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $this->options = [['value' => '', 'label' => __('-- Select Attribute --')]];

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('frontend_input', ['in' => ['select', 'multiselect']]);
        $collection->addFieldToFilter('is_filterable', ['gt' => 0]);
        $collection->setOrder('frontend_label', 'ASC');

        foreach ($collection as $attr) {
            $code = $attr->getAttributeCode();
            $label = $attr->getFrontendLabel() ?: $code;
            $this->options[] = [
                'value' => $code,
                'label' => $label . ' (' . $code . ')',
            ];
        }

        return $this->options;
    }
}
