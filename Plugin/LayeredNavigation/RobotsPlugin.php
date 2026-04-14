<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\LayeredNavigation;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\LayeredNavigation\Block\Navigation;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\CanonicalResolverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * When layered-navigation filters are applied to a category listing we
 * emit `noindex,follow` and rewrite the canonical to point at the base
 * (unfiltered) category URL.
 */
class RobotsPlugin
{
    /** @var string[] Query keys that should never be treated as filters. */
    private const NON_FILTER_KEYS = ['p', 'product_list_limit', 'product_list_order', 'product_list_dir', 'product_list_mode', 'q', 'id', 'cat'];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly PageConfig $pageConfig,
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
        private readonly CanonicalResolverInterface $canonicalResolver,
        private readonly SeoConfig $seoConfig
    ) {
    }

    public function aroundSetLayout(Navigation $subject, callable $proceed, LayoutInterface $layout)
    {
        $value = $proceed($layout);
        $this->apply();
        return $value;
    }

    private function apply(): void
    {
        if (!$this->seoConfig->isEnabled() || !$this->seoConfig->isNoindexFiltered() || !$this->hasFilterParams()) {
            return;
        }
        $this->pageConfig->setRobots('noindex,follow');
        // Canonical is handled by Block\Head\Canonical (via ViewModel\Canonical)
        // which is pagination-aware.  Adding it here via addRemotePageAsset
        // would create a duplicate <link rel="canonical"> tag.
    }

    private function hasFilterParams(): bool
    {
        $params = $this->request->getParams();
        if (!is_array($params) || $params === []) {
            return false;
        }
        foreach ($params as $key => $value) {
            if (in_array((string) $key, self::NON_FILTER_KEYS, true)) {
                continue;
            }
            if ($value !== null && $value !== '') {
                return true;
            }
        }
        return false;
    }
}
