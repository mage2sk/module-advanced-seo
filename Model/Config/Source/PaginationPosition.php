<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the "Pagination Label Position" dropdown in system configuration.
 *
 * Determines where (or whether) a page-number indicator is appended to
 * the meta title on paginated category/search pages.
 */
class PaginationPosition implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'prefix', 'label' => __('Before Title')],
            ['value' => 'suffix', 'label' => __('After Title')],
            ['value' => 'none',   'label' => __("Don't Add")],
        ];
    }
}
