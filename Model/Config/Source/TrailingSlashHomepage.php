<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class TrailingSlashHomepage implements OptionSourceInterface
{
    public const ADD    = 'add';
    public const REMOVE = 'remove';
    public const NONE   = 'none';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::ADD,    'label' => __('Add Trailing Slash')],
            ['value' => self::REMOVE, 'label' => __('Remove Trailing Slash')],
            ['value' => self::NONE,   'label' => __('No Change')],
        ];
    }
}
