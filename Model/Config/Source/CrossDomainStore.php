<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\System\Store as SystemStore;

class CrossDomainStore implements OptionSourceInterface
{
    public function __construct(
        private readonly SystemStore $systemStore
    ) {
    }

    public function toOptionArray(): array
    {
        return array_merge(
            [['value' => '0', 'label' => __('None (keep the current store domain)')]],
            $this->systemStore->getStoreValuesForForm(false, false)
        );
    }
}
