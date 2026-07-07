<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PaginationPosition implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'prefix', 'label' => __('Before Title')],
            ['value' => 'suffix', 'label' => __('After Title')],
            ['value' => 'none',   'label' => __("Don't Add")],
        ];
    }
}
