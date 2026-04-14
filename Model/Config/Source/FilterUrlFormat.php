<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class FilterUrlFormat implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'short', 'label' => __('Short (e.g. /category/color-red-size-xl.html)')],
            ['value' => 'long',  'label' => __('Long (e.g. /category/color/red/size/xl.html)')],
        ];
    }
}
