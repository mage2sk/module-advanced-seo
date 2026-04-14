<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Url;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Psr\Log\LoggerInterface;

/**
 * After a product is saved, detects whether the `visibility` attribute changed.
 * When it has, regenerates URL rewrites so the product immediately becomes
 * reachable (or unreachable) at its canonical URL.
 *
 * Plugin target (per di.xml): Magento\Catalog\Model\Product::save
 *
 * Guarded by the same "Use Short Category URL" feature flag so the rewrite
 * regeneration only fires when the advanced URL feature set is active.
 */
class ProductVisibilityUrlPlugin
{
    private const XML_PATH_USE_SHORT_CATEGORY_URL = 'panth_seo/canonical/use_short_category_url';

    public function __construct(
        private readonly ProductUrlRewriteGenerator $urlRewriteGenerator,
        private readonly UrlPersistInterface $urlPersist,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * Regenerate URL rewrites when product visibility changes.
     *
     * @param Product $subject The product model being saved.
     * @param Product $result  The product returned by the original save().
     * @return Product
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSave(Product $subject, Product $result): Product
    {
        try {
            if (!$this->isEnabled((int) $result->getStoreId())) {
                return $result;
            }

            if (!$this->hasVisibilityChanged($result)) {
                return $result;
            }

            // Do not regenerate URLs for disabled products -- they should not
            // have storefront rewrites at all.
            if ((int) $result->getStatus() === \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED) {
                return $result;
            }

            $newVisibility = (int) $result->getVisibility();

            // Product moved to "Not Visible Individually": remove its rewrites
            // so stale URLs don't linger.
            if ($newVisibility === Visibility::VISIBILITY_NOT_VISIBLE) {
                $this->urlPersist->deleteByData([
                    \Magento\UrlRewrite\Service\V1\Data\UrlRewrite::ENTITY_ID   => $result->getId(),
                    \Magento\UrlRewrite\Service\V1\Data\UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
                ]);
                return $result;
            }

            // Visibility gained or changed between catalog/search/both:
            // regenerate the full set of URL rewrites for this product.
            $urls = $this->urlRewriteGenerator->generate($result);
            if ($urls) {
                $this->urlPersist->replace($urls);
            }
        } catch (\Throwable $e) {
            // Never break the save flow -- log and continue.
            $this->logger->error(
                '[PanthSEO] Failed to regenerate URL rewrites after visibility change',
                [
                    'product_id' => $result->getId(),
                    'error'      => $e->getMessage(),
                ]
            );
        }

        return $result;
    }

    /**
     * Compare original visibility value against current to detect a change.
     */
    private function hasVisibilityChanged(Product $product): bool
    {
        $origData = $product->getOrigData('visibility');

        // No original data means this is a new product -- nothing to compare.
        if ($origData === null) {
            return false;
        }

        $newData = $product->getData('visibility');

        return (int) $origData !== (int) $newData;
    }

    private function isEnabled(int $storeId): bool
    {
        return $this->seoConfig->isEnabled($storeId ?: null)
            && $this->scopeConfig->isSetFlag(
                self::XML_PATH_USE_SHORT_CATEGORY_URL,
                ScopeInterface::SCOPE_STORE,
                $storeId ?: null
            );
    }
}
