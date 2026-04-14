<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for trailing slash behavior on the homepage canonical URL.
 *
 * - add    : ensure the homepage canonical ends with a trailing slash.
 * - remove : strip the trailing slash from the homepage canonical.
 * - none   : leave the homepage URL as-is (no modification).
 */
class TrailingSlashHomepage implements OptionSourceInterface
{
    public const ADD    = 'add';
    public const REMOVE = 'remove';
    public const NONE   = 'none';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::ADD,    'label' => __('Add Trailing Slash')],
            ['value' => self::REMOVE, 'label' => __('Remove Trailing Slash')],
            ['value' => self::NONE,   'label' => __('No Change')],
        ];
    }
}
