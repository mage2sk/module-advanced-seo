<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for crosslink reference types.
 */
class CrosslinkReferenceType implements OptionSourceInterface
{
    public const TYPE_URL = 'url';
    public const TYPE_PRODUCT_SKU = 'product_sku';
    public const TYPE_CATEGORY_ID = 'category_id';

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::TYPE_URL, 'label' => __('Custom URL')],
            ['value' => self::TYPE_PRODUCT_SKU, 'label' => __('Product by SKU')],
            ['value' => self::TYPE_CATEGORY_ID, 'label' => __('Category by ID')],
        ];
    }
}
