<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the return policy type configuration.
 *
 * Used in system.xml for `panth_seo/structured_data/return_policy_type`.
 */
class ReturnPolicyType implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'refund',   'label' => __('Refund')],
            ['value' => 'exchange', 'label' => __('Exchange')],
        ];
    }
}
