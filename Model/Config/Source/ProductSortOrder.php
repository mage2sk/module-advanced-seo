<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ProductSortOrder implements OptionSourceInterface
{
    public const NAME_ASC  = 'name';
    public const NAME_DESC = 'name_desc';
    public const NEWEST    = 'newest';
    public const OLDEST    = 'oldest';
    public const PRICE     = 'price';
    public const POSITION  = 'position';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::NAME_ASC,  'label' => __('Name (A-Z)')],
            ['value' => self::NAME_DESC, 'label' => __('Name (Z-A)')],
            ['value' => self::NEWEST,    'label' => __('Newest')],
            ['value' => self::OLDEST,    'label' => __('Oldest')],
            ['value' => self::PRICE,     'label' => __('Price (low to high)')],
            ['value' => self::POSITION,  'label' => __('Position')],
        ];
    }
}
