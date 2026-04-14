<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the "Product Condition" dropdown in system configuration.
 *
 * Maps to schema.org item-condition enumeration values used in Product
 * structured data (Offer / AggregateOffer).
 */
class ProductCondition implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'new',          'label' => __('New')],
            ['value' => 'used',         'label' => __('Used')],
            ['value' => 'refurbished',  'label' => __('Refurbished')],
            ['value' => 'damaged',      'label' => __('Damaged')],
        ];
    }
}
