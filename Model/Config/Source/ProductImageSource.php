<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ProductImageSource implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'base_image',  'label' => __('Base Image')],
            ['value' => 'small_image', 'label' => __('Small Image')],
            ['value' => 'thumbnail',   'label' => __('Thumbnail')],
        ];
    }
}
