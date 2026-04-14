<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class RedirectTargetStrategy implements ArrayInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'parent_category', 'label' => __('Parent Category')],
            ['value' => 'homepage', 'label' => __('Homepage')],
            ['value' => 'custom_url', 'label' => __('Custom URL')],
        ];
    }
}
