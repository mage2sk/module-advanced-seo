<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Provides breadcrumb format options for the admin configuration.
 *
 * - shortest: selects the shallowest category path (fewest ancestors).
 * - longest:  selects the deepest category path (most ancestors).
 */
class BreadcrumbFormat implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'shortest', 'label' => __('Shortest Path')],
            ['value' => 'longest',  'label' => __('Longest Path / Deepest Category')],
        ];
    }
}
