<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

class LayeredNavCanonical extends AbstractSource
{
    public const USE_GLOBAL = 'use_global';
    public const CATEGORY   = 'category';
    public const FILTERED   = 'filtered';
    public const NOINDEX    = 'noindex';

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

    public function toOptionArray(): array
    {
        return $this->getAllOptions();
    }
}
