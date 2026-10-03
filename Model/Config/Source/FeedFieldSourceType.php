<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class FeedFieldSourceType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'attribute', 'label' => __('Product Attribute')],
            ['value' => 'static', 'label' => __('Static Value')],
            ['value' => 'template', 'label' => __('Template Token')],
            ['value' => 'parent_attribute', 'label' => __('Parent Attribute')],
        ];
    }
}
