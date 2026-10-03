<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class CompressType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '',     'label' => __('None')],
            ['value' => 'zip',  'label' => __('ZIP')],
            ['value' => 'gzip', 'label' => __('GZIP')],
        ];
    }
}
