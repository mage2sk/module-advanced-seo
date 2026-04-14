<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Crosslink;

use Magento\Cms\Model\Template\FilterProvider;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Crosslink\ReplacementService;

/**
 * After-plugin on CMS Template FilterProvider to inject crosslink anchors
 * into CMS page/block content after Magento processes widgets and directives.
 */
class CmsFilterPlugin
{
    public function __construct(
        private readonly ReplacementService $replacementService,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * Intercept getPageFilter to wrap the returned filter with crosslink processing.
     *
     * @param FilterProvider $subject
     * @param object         $result  The template filter instance.
     * @return object Wrapped filter that post-processes output with crosslinks.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetPageFilter(FilterProvider $subject, object $result): object
    {
        if (!$this->isEnabled()) {
            return $result;
        }

        return new CrosslinkFilterDecorator(
            $result,
            $this->replacementService,
            $this->storeManager,
            'cms'
        );
    }

    /**
     * Intercept getBlockFilter to wrap the returned filter with crosslink processing.
     *
     * @param FilterProvider $subject
     * @param object         $result  The template filter instance.
     * @return object Wrapped filter that post-processes output with crosslinks.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetBlockFilter(FilterProvider $subject, object $result): object
    {
        if (!$this->isEnabled()) {
            return $result;
        }

        return new CrosslinkFilterDecorator(
            $result,
            $this->replacementService,
            $this->storeManager,
            'cms'
        );
    }

    private function isEnabled(): bool
    {
        return $this->seoConfig->isEnabled()
            && $this->scopeConfig->isSetFlag(
                'panth_seo/crosslinks/crosslinks_enabled',
                ScopeInterface::SCOPE_STORE
            );
    }
}
