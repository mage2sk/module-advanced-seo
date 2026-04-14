<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\FilterUrl;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Theme\Block\Html\Pager;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\FilterUrl\UrlBuilder;

/**
 * Plugin on Magento\Theme\Block\Html\Pager::getPagerUrl()
 *
 * Preserves SEO-friendly filter URL segments in pagination links.
 */
class PagerPlugin
{
    public function __construct(
        private readonly UrlBuilder $urlBuilder,
        private readonly Config $config,
        private readonly RequestInterface $request,
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetPagerUrl(Pager $subject, callable $proceed, array $params = []): string
    {
        if (!$this->isEnabled()) {
            return $proceed($params);
        }

        $category = $this->registry->registry('current_category');
        if ($category === null) {
            return $proceed($params);
        }

        $activeFilters = $this->collectActiveFilters();
        if ($activeFilters === []) {
            return $proceed($params);
        }

        $storeId     = (int) $this->storeManager->getStore()->getId();
        $categoryUrl = $category->getUrl();

        $url = $this->urlBuilder->build($categoryUrl, $activeFilters, $storeId);
        if ($url === $categoryUrl) {
            return $proceed($params);
        }

        // Append pagination query params to the friendly filter URL.
        $queryParts = [];
        foreach ($params as $key => $value) {
            if ($value !== null) {
                $queryParts[$key] = $value;
            }
        }

        if ($queryParts !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($queryParts);
        }

        return $url;
    }

    /**
     * @return array<int, array{attribute_code: string, option_id: int}>
     */
    private function collectActiveFilters(): array
    {
        $params  = $this->request->getParams();
        $filters = [];

        $nonFilterKeys = ['p', 'product_list_limit', 'product_list_order', 'product_list_dir',
            'product_list_mode', 'q', 'id', 'cat'];

        foreach ($params as $key => $value) {
            if (in_array($key, $nonFilterKeys, true) || $value === null || $value === '') {
                continue;
            }
            $filters[] = [
                'attribute_code' => (string) $key,
                'option_id'      => (int) $value,
            ];
        }

        return $filters;
    }

    private function isEnabled(): bool
    {
        return $this->config->isEnabled()
            && $this->config->isFilterUrlEnabled();
    }
}
