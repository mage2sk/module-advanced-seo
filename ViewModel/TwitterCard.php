<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Social\TwitterCardResolver;

/**
 * Hyva-safe ViewModel exposing Twitter Card tags to templates.
 */
class TwitterCard implements ArgumentInterface
{
    public const XML_TWITTER_ENABLED = 'panth_seo/social/twitter_enabled';

    public function __construct(
        private readonly TwitterCardResolver $twitterCardResolver,
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether Twitter Card output is enabled.
     */
    public function isEnabled(): bool
    {
        try {
            return $this->config->isEnabled()
                && $this->scopeConfig->isSetFlag(self::XML_TWITTER_ENABLED, ScopeInterface::SCOPE_STORE);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get resolved Twitter Card tags if enabled.
     *
     * @return array<string, string>
     */
    public function getTags(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        try {
            return $this->twitterCardResolver->resolve();
        } catch (\Throwable) {
            return [];
        }
    }
}
