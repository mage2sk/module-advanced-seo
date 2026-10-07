<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class CanonicalEntityType implements OptionSourceInterface
{
    public function __construct(
        private readonly bool $withNone = false
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];
        if ($this->withNone) {
            $options[] = ['value' => '', 'label' => __('None (use the Canonical URL)')];
        }
        $options[] = ['value' => 'product', 'label' => __('Product')];
        $options[] = ['value' => 'category', 'label' => __('Category')];
        $options[] = ['value' => 'cms_page', 'label' => __('CMS Page')];

        return $options;
    }
}
