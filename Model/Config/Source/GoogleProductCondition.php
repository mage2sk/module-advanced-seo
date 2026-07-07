<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class GoogleProductCondition implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'new',         'label' => __('New')],
            ['value' => 'used',        'label' => __('Used')],
            ['value' => 'refurbished', 'label' => __('Refurbished')],
        ];
    }
}
