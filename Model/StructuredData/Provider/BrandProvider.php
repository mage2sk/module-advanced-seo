<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData\Provider;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;

/**
 * Emits a schema.org Brand node based on the current product's manufacturer
 * attribute or a store-level default.
 */
class BrandProvider extends AbstractProvider
{
    private const XML_PATH_DEFAULT_BRAND = 'panth_seo/structured_data/default_brand';

    public function __construct(
        Registry $registry,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        Config $config,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($registry, $request, $storeManager, $config);
    }

    public function getCode(): string
    {
        return 'brand';
    }

    public function getJsonLd(): array
    {
        $product = $this->getCurrentProduct();
        if ($product === null) {
            return [];
        }

        $brand = '';
        $brandAttr = $this->config->getBrandAttribute() ?: 'manufacturer';
        if ($product->hasData($brandAttr)) {
            $text = $product->getAttributeText($brandAttr);
            $brand = is_string($text) ? $text : '';
        }
        if ($brand === '') {
            $brand = trim((string) $this->scopeConfig->getValue(
                self::XML_PATH_DEFAULT_BRAND,
                ScopeInterface::SCOPE_STORE
            ));
        }
        if ($brand === '') {
            return [];
        }

        return [
            '@type' => 'Brand',
            'name' => $brand,
        ];
    }
}
