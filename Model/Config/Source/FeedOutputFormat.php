<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class FeedOutputFormat implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'xml', 'label' => __('XML (RSS 2.0)')],
            ['value' => 'csv', 'label' => __('CSV')],
        ];
    }
}
