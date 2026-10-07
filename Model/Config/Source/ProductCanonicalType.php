<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ProductCanonicalType implements OptionSourceInterface
{
    public const WITHOUT_CATEGORY = 'without_category';
    public const SHORTEST         = 'shortest';
    public const LONGEST          = 'longest';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::WITHOUT_CATEGORY, 'label' => __('Product URL without category path')],
            ['value' => self::SHORTEST,         'label' => __('Product URL with shortest category path')],
            ['value' => self::LONGEST,          'label' => __('Product URL with longest/deepest category path')],
        ];
    }
}
