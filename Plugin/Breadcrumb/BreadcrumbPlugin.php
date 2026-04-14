<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Breadcrumb;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Psr\Log\LoggerInterface;

/**
 * Re-sorts the breadcrumb path for product pages based on category
 * breadcrumb priority weights and/or a configurable path-length strategy.
 *
 * When the `enable_breadcrumb_priority` config flag is active the plugin
 * evaluates every category the current product belongs to, walks each
 * category's ancestor chain, sums up `breadcrumbs_priority` attribute
 * values, and replaces the breadcrumb trail with the winning path.
 *
 * When a `breadcrumb_format` of "shortest" or "longest" is configured the
 * category depth is used as a secondary (or sole) selection criterion.
 */
class BreadcrumbPlugin
{
    private const XML_PATH_ENABLED = 'panth_seo/breadcrumbs/enable_breadcrumb_priority';
    private const XML_PATH_FORMAT  = 'panth_seo/breadcrumbs/breadcrumb_format';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * @param  array<string, array<string, mixed>> $result
     * @return array<string, array<string, mixed>>
     */
    public function afterGetBreadcrumbPath(CatalogHelper $subject, array $result): array
    {
        if (!$this->isEnabled()) {
            return $result;
        }

        $product = $this->registry->registry('current_product');
        if (!$product instanceof Product) {
            return $result;
        }

        $categoryIds = $product->getCategoryIds();
        if (empty($categoryIds)) {
            return $result;
        }

        $bestPath = $this->resolveBestCategoryPath($categoryIds);
        if ($bestPath === null) {
            return $result;
        }

        return $this->buildBreadcrumbTrail($bestPath, $product);
    }

    private function isEnabled(): bool
    {
        return $this->seoConfig->isEnabled()
            && $this->scopeConfig->isSetFlag(
                self::XML_PATH_ENABLED,
                ScopeInterface::SCOPE_STORE
            );
    }

    /**
     * Evaluate all candidate category paths and return the best ancestor
     * chain ordered root-first, or null when no valid path exists.
     *
     * @param  int[]|string[] $categoryIds
     * @return array<int, \Magento\Catalog\Api\Data\CategoryInterface>|null
     */
    private function resolveBestCategoryPath(array $categoryIds): ?array
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $format  = $this->getFormat();

        /** @var array<int, array{path: array<int, \Magento\Catalog\Api\Data\CategoryInterface>, weight: int, depth: int}> $candidates */
        $candidates = [];

        foreach ($categoryIds as $categoryId) {
            try {
                $category = $this->categoryRepository->get((int) $categoryId, $storeId);
            } catch (NoSuchEntityException) {
                continue;
            }

            if (!$category->getIsActive()) {
                continue;
            }

            $ancestors = $this->loadAncestorChain($category, $storeId);
            if ($ancestors === null) {
                continue;
            }

            $totalWeight = 0;
            foreach ($ancestors as $ancestor) {
                $totalWeight += (int) ($ancestor->getCustomAttribute('breadcrumbs_priority')?->getValue() ?? 0);
            }

            $candidates[] = [
                'path'   => $ancestors,
                'weight' => $totalWeight,
                'depth'  => count($ancestors),
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function (array $a, array $b) use ($format): int {
            // Primary sort: highest total weight first
            $weightCmp = $b['weight'] <=> $a['weight'];
            if ($weightCmp !== 0) {
                return $weightCmp;
            }

            // Secondary sort: path length strategy
            return match ($format) {
                'shortest' => $a['depth'] <=> $b['depth'],
                'longest'  => $b['depth'] <=> $a['depth'],
                default    => 0,
            };
        });

        return $candidates[0]['path'];
    }

    /**
     * Walk the category's path upward and return an ordered array of active
     * ancestor categories (excluding root categories with level < 2).
     *
     * @return array<int, \Magento\Catalog\Api\Data\CategoryInterface>|null
     */
    private function loadAncestorChain(
        \Magento\Catalog\Api\Data\CategoryInterface $category,
        int $storeId
    ): ?array {
        $pathIds = explode('/', (string) $category->getPath());
        $chain   = [];

        foreach ($pathIds as $ancestorId) {
            $ancestorId = (int) $ancestorId;

            try {
                $ancestor = $this->categoryRepository->get($ancestorId, $storeId);
            } catch (NoSuchEntityException) {
                continue;
            }

            // Skip the invisible root categories (level 0 and 1)
            if ((int) $ancestor->getLevel() < 2) {
                continue;
            }

            if (!$ancestor->getIsActive()) {
                return null; // broken chain -- entire path is invalid
            }

            $chain[] = $ancestor;
        }

        return empty($chain) ? null : $chain;
    }

    /**
     * Reconstruct the Magento breadcrumb array from the resolved ancestor
     * chain plus the current product.
     *
     * @param  array<int, \Magento\Catalog\Api\Data\CategoryInterface> $categoryChain
     * @return array<string, array<string, mixed>>
     */
    private function buildBreadcrumbTrail(array $categoryChain, Product $product): array
    {
        $crumbs = [];

        // Home crumb
        $crumbs['home'] = [
            'label' => __('Home'),
            'title' => null,
            'link'  => $this->storeManager->getStore()->getBaseUrl(),
            'first' => true,
            'last'  => false,
        ];

        // Category crumbs
        foreach ($categoryChain as $index => $category) {
            $crumbs['category' . $category->getId()] = [
                'label' => $category->getName(),
                'title' => null,
                'link'  => $category->getUrl(),
                'first' => false,
                'last'  => false,
            ];
        }

        // Product crumb (always last)
        $crumbs['product'] = [
            'label' => $product->getName(),
            'title' => null,
            'link'  => '',
            'first' => false,
            'last'  => true,
        ];

        return $crumbs;
    }

    private function getFormat(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_FORMAT,
            ScopeInterface::SCOPE_STORE
        );
    }
}
