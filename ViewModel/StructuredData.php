<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\StructuredData\Composite;

/**
 * Hyva-safe ViewModel exposing the aggregated JSON-LD document to templates.
 */
class StructuredData implements ArgumentInterface
{
    public function __construct(
        private readonly Composite $composite,
        private readonly Config $config
    ) {
    }

    public function isEnabled(): bool
    {
        try {
            return $this->config->isEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    public function getJson(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }
        try {
            return $this->composite->build();
        } catch (\Throwable) {
            return '';
        }
    }
}
