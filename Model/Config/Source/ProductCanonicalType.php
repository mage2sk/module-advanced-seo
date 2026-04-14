<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for product canonical URL type.
 *
 * Controls how the canonical URL is built for product pages:
 * - without_category: bare product URL, no category path prefix.
 * - shortest:         product URL with the shallowest assigned category path.
 * - longest:          product URL with the deepest assigned category path.
 */
class ProductCanonicalType implements OptionSourceInterface
{
    public const WITHOUT_CATEGORY = 'without_category';
    public const SHORTEST         = 'shortest';
    public const LONGEST          = 'longest';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::WITHOUT_CATEGORY, 'label' => __('Product URL without category path')],
            ['value' => self::SHORTEST,         'label' => __('Product URL with shortest category path')],
            ['value' => self::LONGEST,          'label' => __('Product URL with longest/deepest category path')],
        ];
    }
}
