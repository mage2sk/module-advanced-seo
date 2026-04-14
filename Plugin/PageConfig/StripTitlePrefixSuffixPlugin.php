<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\PageConfig;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Page\Title;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Strips the native Magento title prefix and suffix when Panth SEO
 * templates are active, preventing doubled prefixes/suffixes in <title>.
 */
class StripTitlePrefixSuffixPlugin
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SeoConfig $config
    ) {
    }

    /**
     * @param Title  $subject
     * @param string $result
     * @return string
     */
    public function afterGet(Title $subject, string $result): string
    {
        if (!$this->config->isEnabled()) {
            return $result;
        }

        // Honour the admin toggle: only strip the native prefix/suffix when
        // "Strip Native Title Prefix/Suffix" is enabled. Without this guard
        // the plugin always stripped, making the setting a no-op.
        if (!$this->config->isStripTitlePrefixSuffix()) {
            return $result;
        }

        $prefix = trim((string) $this->scopeConfig->getValue(
            'design/head/title_prefix',
            ScopeInterface::SCOPE_STORE
        ));
        $suffix = trim((string) $this->scopeConfig->getValue(
            'design/head/title_suffix',
            ScopeInterface::SCOPE_STORE
        ));

        if ($prefix !== '' && str_starts_with($result, $prefix)) {
            $result = ltrim(substr($result, strlen($prefix)));
        }
        if ($suffix !== '' && str_ends_with($result, $suffix)) {
            $result = rtrim(substr($result, 0, -strlen($suffix)));
        }

        return $result;
    }
}
