<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Source model for Twitter Card type dropdown in system configuration.
 */
class TwitterCardType implements ArrayInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'summary', 'label' => __('Summary')],
            ['value' => 'summary_large_image', 'label' => __('Summary with Large Image')],
        ];
    }
}
