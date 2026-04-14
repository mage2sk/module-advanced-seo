<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Meta;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\View\Page\Title;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Plugin on Magento\Framework\View\Page\Title::get().
 *
 * When `panth_seo/meta/strip_title_prefix_suffix` is enabled, strips the
 * store-configured title prefix and suffix from the rendered page title.
 * Only fires on frontend area to avoid interfering with admin panels.
 */
class StripTitlePrefixSuffix
{
    private const XML_STRIP_ENABLED = 'panth_seo/meta/strip_title_prefix_suffix';
    private const XML_TITLE_PREFIX  = 'design/head/title_prefix';
    private const XML_TITLE_SUFFIX  = 'design/head/title_suffix';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly State $appState,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * After-plugin on Title::get().
     */
    public function afterGet(Title $subject, string $result): string
    {
        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }

        if (!$this->isFrontend()) {
            return $result;
        }

        if (!$this->scopeConfig->isSetFlag(self::XML_STRIP_ENABLED, ScopeInterface::SCOPE_STORE)) {
            return $result;
        }

        $prefix = (string) $this->scopeConfig->getValue(self::XML_TITLE_PREFIX, ScopeInterface::SCOPE_STORE);
        $suffix = (string) $this->scopeConfig->getValue(self::XML_TITLE_SUFFIX, ScopeInterface::SCOPE_STORE);

        if ($prefix !== '') {
            $result = str_replace($prefix, '', $result);
        }

        if ($suffix !== '') {
            $result = str_replace($suffix, '', $result);
        }

        return trim($result);
    }

    private function isFrontend(): bool
    {
        try {
            return $this->appState->getAreaCode() === 'frontend';
        } catch (\Throwable) {
            return false;
        }
    }
}
