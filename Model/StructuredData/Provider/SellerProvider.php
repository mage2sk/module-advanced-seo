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
 * Emits a seller node (Organization / LocalBusiness / Store / OnlineStore)
 * that can be referenced by Product Offer nodes via `seller: { @id: ... }`.
 *
 * Config fields:
 *  - panth_seo/structured_data/business_type  (Organization|LocalBusiness|Store|OnlineStore)
 *
 * Name comes from store name; URL from store base URL.
 * For LocalBusiness / Store, address fields are read from store contact info config.
 */
class SellerProvider extends AbstractProvider
{
    private const XML_BUSINESS_TYPE = 'panth_seo/structured_data/business_type';
    private const XML_STORE_NAME    = 'general/store_information/name';
    private const XML_STORE_PHONE   = 'general/store_information/phone';
    private const XML_STORE_STREET1 = 'general/store_information/street_line1';
    private const XML_STORE_STREET2 = 'general/store_information/street_line2';
    private const XML_STORE_CITY    = 'general/store_information/city';
    private const XML_STORE_REGION  = 'general/store_information/region_id';
    private const XML_STORE_POSTCODE = 'general/store_information/postcode';
    private const XML_STORE_COUNTRY = 'general/store_information/country_id';

    private const TYPES_WITH_ADDRESS = ['LocalBusiness', 'Store'];

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
        return 'seller';
    }

    public function isApplicable(): bool
    {
        return $this->getCurrentProduct() !== null;
    }

    public function getJsonLd(): array
    {
        try {
            $store = $this->storeManager->getStore();
            $storeId = (int) $store->getId();
            $baseUrl = rtrim($store->getBaseUrl(), '/') . '/';
        } catch (\Throwable) {
            return [];
        }

        $businessType = $this->getBusinessType($storeId);
        $name = $this->getStoreName($storeId);

        $node = [
            '@type' => $businessType,
            '@id'   => $baseUrl . '#seller',
            'name'  => $name,
            'url'   => $baseUrl,
        ];

        // Add address for physical business types
        if (in_array($businessType, self::TYPES_WITH_ADDRESS, true)) {
            $address = $this->buildAddress($storeId);
            if ($address !== []) {
                $node['address'] = $address;
            }

            $phone = (string) ($this->scopeValue(self::XML_STORE_PHONE, $storeId) ?? '');
            if ($phone !== '') {
                $node['telephone'] = $phone;
            }
        }

        return $node;
    }

    private function getBusinessType(int $storeId): string
    {
        $type = (string) ($this->scopeValue(self::XML_BUSINESS_TYPE, $storeId) ?? '');
        $allowed = ['Organization', 'LocalBusiness', 'Store', 'OnlineStore'];

        if ($type !== '' && in_array($type, $allowed, true)) {
            return $type;
        }

        return 'Organization';
    }

    private function getStoreName(int $storeId): string
    {
        $name = (string) ($this->scopeValue(self::XML_STORE_NAME, $storeId) ?? '');
        if ($name !== '') {
            return $name;
        }

        try {
            return (string) $this->storeManager->getStore()->getName();
        } catch (\Throwable) {
            return 'Store';
        }
    }

    /**
     * @return array<string,string>
     */
    private function buildAddress(int $storeId): array
    {
        $street1 = (string) ($this->scopeValue(self::XML_STORE_STREET1, $storeId) ?? '');
        $street2 = (string) ($this->scopeValue(self::XML_STORE_STREET2, $storeId) ?? '');
        $city = (string) ($this->scopeValue(self::XML_STORE_CITY, $storeId) ?? '');
        $region = (string) ($this->scopeValue(self::XML_STORE_REGION, $storeId) ?? '');
        $postcode = (string) ($this->scopeValue(self::XML_STORE_POSTCODE, $storeId) ?? '');
        $country = (string) ($this->scopeValue(self::XML_STORE_COUNTRY, $storeId) ?? '');

        if ($street1 === '' && $city === '' && $postcode === '' && $country === '') {
            return [];
        }

        $addr = ['@type' => 'PostalAddress'];

        $streetAddress = trim($street1 . ($street2 !== '' ? ', ' . $street2 : ''));
        if ($streetAddress !== '') {
            $addr['streetAddress'] = $streetAddress;
        }
        if ($city !== '') {
            $addr['addressLocality'] = $city;
        }
        if ($region !== '') {
            $addr['addressRegion'] = $region;
        }
        if ($postcode !== '') {
            $addr['postalCode'] = $postcode;
        }
        if ($country !== '') {
            $addr['addressCountry'] = $country;
        }

        return $addr;
    }

    private function scopeValue(string $path, int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
