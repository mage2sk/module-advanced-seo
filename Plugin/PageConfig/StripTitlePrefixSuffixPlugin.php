<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\PageConfig;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Page\Title;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

class StripTitlePrefixSuffixPlugin
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SeoConfig $config
    ) {
    }

    public function afterGet(Title $subject, string $result): string
    {
        if (!$this->config->isEnabled()) {
            return $result;
        }

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
