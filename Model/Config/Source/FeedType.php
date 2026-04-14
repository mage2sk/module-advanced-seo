<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for feed profile types.
 */
class FeedType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'google_shopping', 'label' => __('Google Shopping')],
            ['value' => 'facebook',        'label' => __('Facebook Product Catalog')],
            ['value' => 'custom_xml',      'label' => __('Custom XML')],
            ['value' => 'custom_csv',      'label' => __('Custom CSV')],
        ];
    }
}
