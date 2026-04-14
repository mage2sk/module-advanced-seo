<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\FilterUrl;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Swatches\Block\LayeredNavigation\RenderLayered;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\FilterUrl\UrlBuilder;

/**
 * Plugin on Magento\Swatches\Block\LayeredNavigation\RenderLayered::buildUrl()
 *
 * Rewrites swatch filter URLs to SEO-friendly paths.
 */
class SwatchUrlPlugin
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
    public function aroundBuildUrl(RenderLayered $subject, callable $proceed, string $attributeCode, int $optionId): string
    {
        if (!$this->isEnabled()) {
            return $proceed($attributeCode, $optionId);
        }

        $category = $this->registry->registry('current_category');
        if ($category === null) {
            return $proceed($attributeCode, $optionId);
        }

        $storeId     = (int) $this->storeManager->getStore()->getId();
        $categoryUrl = $category->getUrl();

        $activeFilters   = $this->collectActiveFilters();
        $activeFilters[] = [
            'attribute_code' => $attributeCode,
            'option_id'      => $optionId,
        ];

        $url = $this->urlBuilder->build($categoryUrl, $activeFilters, $storeId);
        if ($url === $categoryUrl) {
            return $proceed($attributeCode, $optionId);
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
