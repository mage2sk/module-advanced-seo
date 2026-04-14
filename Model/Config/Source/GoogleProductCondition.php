<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for Google Merchant Center product condition.
 *
 * Google Shopping accepts exactly three condition values:
 *   new, used, refurbished
 *
 * @see https://support.google.com/merchants/answer/6324469
 */
class GoogleProductCondition implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'new',         'label' => __('New')],
            ['value' => 'used',        'label' => __('Used')],
            ['value' => 'refurbished', 'label' => __('Refurbished')],
        ];
    }
}
