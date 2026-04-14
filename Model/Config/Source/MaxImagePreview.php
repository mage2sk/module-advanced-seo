<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the `max-image-preview` robots directive.
 *
 * "none"     = omit the directive entirely
 * "standard" = max-image-preview:standard
 * "large"    = max-image-preview:large (recommended for Google Discover)
 */
class MaxImagePreview implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'large',    'label' => __('large (recommended)')],
            ['value' => 'standard', 'label' => __('standard')],
            ['value' => 'none',     'label' => __('none (omit directive)')],
        ];
    }
}
