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
 * Emits a single Organization node per page. Data is pulled from core store
 * identity fields with `panth_seo/organization/*` overrides + social profile
 * URLs (sameAs).
 */
class OrganizationProvider extends AbstractProvider
{
    public const XML_LEGAL_NAME = 'panth_seo/organization/legal_name';
    public const XML_LOGO       = 'panth_seo/organization/logo';
    public const XML_SAME_AS    = 'panth_seo/organization/same_as';
    public const XML_PHONE      = 'panth_seo/organization/phone';
    public const XML_EMAIL      = 'panth_seo/organization/email';
    public const XML_STREET     = 'panth_seo/organization/street';
    public const XML_LOCALITY   = 'panth_seo/organization/locality';
    public const XML_REGION     = 'panth_seo/organization/region';
    public const XML_POSTCODE   = 'panth_seo/organization/postcode';
    public const XML_COUNTRY    = 'panth_seo/organization/country';

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
        return 'organization';
    }

    public function getJsonLd(): array
    {
        try {
            $store = $this->storeManager->getStore();
            $storeId = (int) $store->getId();
        } catch (\Throwable) {
            return [];
        }

        $name = (string) $this->scopeValue('general/store_information/name', $storeId) ?: $store->getName();
        $url = rtrim($store->getBaseUrl(), '/') . '/';
        $logo = (string) $this->scopeValue(self::XML_LOGO, $storeId);
        $legalName = (string) $this->scopeValue(self::XML_LEGAL_NAME, $storeId);
        $phone = (string) $this->scopeValue(self::XML_PHONE, $storeId) ?: (string) $this->scopeValue('general/store_information/phone', $storeId);
        $email = (string) $this->scopeValue(self::XML_EMAIL, $storeId);

        $node = [
            '@type' => 'Organization',
            '@id'   => $url . '#organization',
            'name'  => $name !== '' ? $name : 'Store',
            'url'   => $url,
        ];
        if ($legalName !== '') {
            $node['legalName'] = $legalName;
        }
        if ($logo !== '') {
            $node['logo'] = [
                '@type' => 'ImageObject',
                'url'   => $logo,
            ];
        }
        if ($phone !== '' || $email !== '') {
            $contact = ['@type' => 'ContactPoint', 'contactType' => 'customer support'];
            if ($phone !== '') {
                $contact['telephone'] = $phone;
            }
            if ($email !== '') {
                $contact['email'] = $email;
            }
            $node['contactPoint'] = [$contact];
        }

        $address = $this->buildAddress($storeId);
        if ($address !== []) {
            $node['address'] = $address;
        }

        $sameAs = $this->buildSameAs($storeId);
        if ($sameAs !== []) {
            $node['sameAs'] = $sameAs;
        }

        return $node;
    }

    /**
     * @return array<string,string>
     */
    private function buildAddress(int $storeId): array
    {
        $street = (string) $this->scopeValue(self::XML_STREET, $storeId);
        $locality = (string) $this->scopeValue(self::XML_LOCALITY, $storeId);
        $region = (string) $this->scopeValue(self::XML_REGION, $storeId);
        $postcode = (string) $this->scopeValue(self::XML_POSTCODE, $storeId);
        $country = (string) $this->scopeValue(self::XML_COUNTRY, $storeId);

        if ($street === '' && $locality === '' && $postcode === '' && $country === '') {
            return [];
        }
        $addr = ['@type' => 'PostalAddress'];
        if ($street !== '') {
            $addr['streetAddress'] = $street;
        }
        if ($locality !== '') {
            $addr['addressLocality'] = $locality;
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

    /**
     * Merge URLs from the legacy `same_as` textarea with dedicated social profile fields.
     *
     * @return array<int,string>
     */
    private function buildSameAs(int $storeId): array
    {
        $out = [];

        // Legacy: free-form textarea (comma / newline separated)
        $raw = (string) $this->scopeValue(self::XML_SAME_AS, $storeId);
        if ($raw !== '') {
            $lines = preg_split('/[\r\n,]+/', $raw) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && filter_var($line, FILTER_VALIDATE_URL)) {
                    $out[] = $line;
                }
            }
        }

        // Dedicated social profile config fields
        $socialUrls = $this->config->getSocialProfileUrls($storeId);
        foreach ($socialUrls as $url) {
            $out[] = $url;
        }

        // Deduplicate while preserving order
        return array_values(array_unique($out));
    }

    private function scopeValue(string $path, int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
