<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\FilterUrl;

use Magento\Catalog\Model\Layer\Filter\Item;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\FilterUrl\UrlBuilder;

/**
 * Plugin on Magento\Catalog\Model\Layer\Filter\Item::getUrl()
 *
 * Replaces the default query-param filter URL with a SEO-friendly path-based URL.
 */
class FilterItemUrlPlugin
{
    public function __construct(
        private readonly UrlBuilder $urlBuilder,
        private readonly Config $config,
        private readonly RequestInterface $request,
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlHelper
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetUrl(Item $subject, callable $proceed): string
    {
        if (!$this->isEnabled()) {
            return $proceed();
        }

        $category = $this->registry->registry('current_category');
        if ($category === null) {
            return $proceed();
        }

        $storeId     = (int) $this->storeManager->getStore()->getId();
        $categoryUrl = $category->getUrl();

        $activeFilters = $this->collectActiveFilters();

        // Add the current filter item being applied.
        $filter = $subject->getFilter();
        $activeFilters[] = [
            'attribute_code' => $filter->getRequestVar(),
            'option_id'      => (int) $subject->getValue(),
        ];

        $url = $this->urlBuilder->build($categoryUrl, $activeFilters, $storeId);
        if ($url === $categoryUrl) {
            return $proceed();
        }

        return $url;
    }

    /**
     * Collect currently active filters from request parameters.
     *
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
