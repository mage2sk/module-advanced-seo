<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the seller business type configuration.
 *
 * Used in system.xml for `panth_seo/structured_data/business_type`.
 */
class BusinessType implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'Organization',   'label' => __('Organization')],
            ['value' => 'LocalBusiness',   'label' => __('Local Business')],
            ['value' => 'Store',           'label' => __('Store')],
            ['value' => 'OnlineStore',     'label' => __('Online Store')],
        ];
    }
}
