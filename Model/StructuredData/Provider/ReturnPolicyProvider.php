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
 * Emits a MerchantReturnPolicy schema.org node on product pages.
 *
 * Config fields used:
 *  - panth_seo/structured_data/return_policy_days  (int, must be > 0 to activate)
 *  - panth_seo/structured_data/return_policy_type  (refund|exchange)
 *  - panth_seo/structured_data/return_policy_fees  (free|custom string)
 *
 * The applicable country is read from the store's country configuration.
 */
class ReturnPolicyProvider extends AbstractProvider
{
    private const XML_RETURN_DAYS = 'panth_seo/structured_data/return_policy_days';
    private const XML_RETURN_TYPE = 'panth_seo/structured_data/return_policy_type';
    private const XML_RETURN_FEES = 'panth_seo/structured_data/return_policy_fees';
    private const XML_STORE_COUNTRY = 'general/country/default';

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
        return 'return_policy';
    }

    public function isApplicable(): bool
    {
        if ($this->getCurrentProduct() === null) {
            return false;
        }

        return $this->getReturnDays() > 0;
    }

    public function getJsonLd(): array
    {
        $days = $this->getReturnDays();
        if ($days <= 0) {
            return [];
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            $storeId = null;
        }

        $country = $this->getStoreCountry($storeId);
        $returnFees = $this->getReturnFees($storeId);

        $node = [
            '@type'                => 'MerchantReturnPolicy',
            'applicableCountry'    => $country,
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays'   => $days,
            'returnMethod'         => 'https://schema.org/ReturnByMail',
            'returnFees'           => $returnFees,
        ];

        $returnType = $this->getReturnType($storeId);
        if ($returnType !== '') {
            $node['returnPolicySeasonalOverride'] = [];
            // Schema.org doesn't have a direct "refund vs exchange" field,
            // but we include it as an additional property for richer data.
            $node['additionalProperty'] = [
                '@type' => 'PropertyValue',
                'name'  => 'Return Type',
                'value' => $returnType,
            ];
        }

        return $node;
    }

    private function getReturnDays(): int
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            $storeId = null;
        }

        return (int) ($this->scopeConfig->getValue(
            self::XML_RETURN_DAYS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?? 0);
    }

    private function getReturnType(?int $storeId): string
    {
        $value = (string) ($this->scopeConfig->getValue(
            self::XML_RETURN_TYPE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?? '');

        return $value;
    }

    private function getReturnFees(?int $storeId): string
    {
        $value = (string) ($this->scopeConfig->getValue(
            self::XML_RETURN_FEES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?? '');

        if ($value === '' || strtolower($value) === 'free') {
            return 'https://schema.org/FreeReturn';
        }

        return $value;
    }

    private function getStoreCountry(?int $storeId): string
    {
        $country = (string) ($this->scopeConfig->getValue(
            self::XML_STORE_COUNTRY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?? '');

        return $country !== '' ? $country : 'US';
    }
}
