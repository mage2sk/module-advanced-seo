<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

/**
 * Source model for the per-attribute `layered_navigation_canonical` setting.
 *
 * Extends AbstractSource (not just OptionSourceInterface) because this class
 * is registered as an EAV attribute source via Setup\Patch\Data. The EAV
 * system calls setAttribute() during flat index rebuild, which requires
 * AbstractSource.
 *
 * Each catalog (filterable) attribute can override the global canonical behavior
 * when it is active as a layered navigation filter:
 *
 * - use_global : defer to the store-level canonical setting (default).
 * - category   : canonical always points to the unfiltered base category URL.
 * - filtered   : canonical points to the filtered page URL.
 * - noindex    : emit a NOINDEX directive instead of a canonical tag.
 */
class LayeredNavCanonical extends AbstractSource
{
    public const USE_GLOBAL = 'use_global';
    public const CATEGORY   = 'category';
    public const FILTERED   = 'filtered';
    public const NOINDEX    = 'noindex';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function getAllOptions(): array
    {
        if ($this->_options === null) {
            $this->_options = [
                ['value' => self::USE_GLOBAL, 'label' => __('Use Global Setting')],
                ['value' => self::CATEGORY,   'label' => __('Base Category URL')],
                ['value' => self::FILTERED,   'label' => __('Filtered Page URL')],
                ['value' => self::NOINDEX,    'label' => __('Set NOINDEX')],
            ];
        }
        return $this->_options;
    }

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return $this->getAllOptions();
    }
}
