<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for panth_seo/hreflang/cms_relation_method.
 *
 * Determines how CMS pages across stores are matched for hreflang:
 *  - by_id:         Same entity_id in different stores.
 *  - by_url_key:    Same identifier (URL key) in different stores.
 *  - by_identifier: Via the panth_seo_hreflang_group table (manual grouping).
 */
class CmsRelationMethod implements OptionSourceInterface
{
    public const BY_ID         = 'by_id';
    public const BY_URL_KEY    = 'by_url_key';
    public const BY_IDENTIFIER = 'by_identifier';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::BY_ID,         'label' => __('Same Page ID')],
            ['value' => self::BY_URL_KEY,    'label' => __('Same URL Key')],
            ['value' => self::BY_IDENTIFIER, 'label' => __('By Hreflang Identifier')],
        ];
    }
}
