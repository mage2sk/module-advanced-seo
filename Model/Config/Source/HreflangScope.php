<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for panth_seo/hreflang/hreflang_scope.
 *
 * Controls whether hreflang alternates are resolved across
 * all stores globally or only within the same website.
 */
class HreflangScope implements OptionSourceInterface
{
    public const SCOPE_WEBSITE = 'website';
    public const SCOPE_GLOBAL  = 'global';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::SCOPE_WEBSITE, 'label' => __('Within Same Website')],
            ['value' => self::SCOPE_GLOBAL,  'label' => __('Across All Websites')],
        ];
    }
}
