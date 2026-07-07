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

    public function afterSave(Product $subject, Product $result): Product
    {
        try {
            if (!$this->isEnabled((int) $result->getStoreId())) {
                return $result;
            }

            if (!$this->hasVisibilityChanged($result)) {
                return $result;
            }

            if ((int) $result->getStatus() === \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED) {
                return $result;
            }

            $newVisibility = (int) $result->getVisibility();

            if ($newVisibility === Visibility::VISIBILITY_NOT_VISIBLE) {
                $this->urlPersist->deleteByData([
                    \Magento\UrlRewrite\Service\V1\Data\UrlRewrite::ENTITY_ID   => $result->getId(),
                    \Magento\UrlRewrite\Service\V1\Data\UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
                ]);
                return $result;
            }

            $urls = $this->urlRewriteGenerator->generate($result);
            if ($urls) {
                $this->urlPersist->replace($urls);
            }
        } catch (\Throwable $e) {
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

    private function hasVisibilityChanged(Product $product): bool
    {
        $origData = $product->getOrigData('visibility');

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
