<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData\Provider;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\OfflineShipping\Model\ResourceModel\Carrier\Tablerate\CollectionFactory as TablerateCollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;

/**
 * Emits multi-region `OfferShippingDetails` nodes on product pages.
 *
 * Priority:
 *   1. Magento table-rate shipping entries (if the module + table exists)
 *   2. Magento flat-rate shipping config per-website/store
 *   3. Falls back to the existing DeliveryMethodProvider's textarea config
 *
 * Each shipping method + country combination becomes a separate
 * OfferShippingDetails node attached to the Product Offer.
 */
class MultiRegionShippingProvider extends AbstractProvider
{
    private const XML_FLATRATE_ACTIVE = 'carriers/flatrate/active';
    private const XML_FLATRATE_PRICE  = 'carriers/flatrate/price';
    private const XML_FLATRATE_TITLE  = 'carriers/flatrate/title';
    private const XML_FLATRATE_NAME   = 'carriers/flatrate/name';

    private const XML_TABLERATE_ACTIVE = 'carriers/tablerate/active';

    private const XML_STORE_COUNTRY    = 'general/country/default';

    private const XML_HANDLING_TIME_MIN = 'panth_seo/structured_data/handling_time_min';
    private const XML_HANDLING_TIME_MAX = 'panth_seo/structured_data/handling_time_max';

    /**
     * Default transit-time estimate when Magento config provides no data.
     */
    private const DEFAULT_TRANSIT_MIN = 2;
    private const DEFAULT_TRANSIT_MAX = 7;

    private const DEFAULT_HANDLING_MIN = 0;
    private const DEFAULT_HANDLING_MAX = 1;

    public function __construct(
        Registry $registry,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ?TablerateCollectionFactory $tablerateCollectionFactory = null
    ) {
        parent::__construct($registry, $request, $storeManager, $config);
    }

    public function getCode(): string
    {
        return 'multiRegionShipping';
    }

    public function isApplicable(): bool
    {
        if ($this->getCurrentProduct() === null) {
            return false;
        }

        // Only emit when there is no manual delivery_methods config
        // (otherwise the DeliveryMethodProvider handles it).
        if (trim($this->config->getDeliveryMethods()) !== '') {
            return false;
        }

        return $this->collectShippingEntries() !== [];
    }

    public function getJsonLd(): array
    {
        $product = $this->getCurrentProduct();
        if ($product === null) {
            return [];
        }

        $entries = $this->collectShippingEntries();
        if ($entries === []) {
            return [];
        }

        $url = (string) $product->getProductUrl();

        try {
            $currency = (string) $this->storeManager->getStore()->getCurrentCurrencyCode();
        } catch (\Throwable) {
            $currency = 'USD';
        }

        $shippingDetails = [];
        foreach ($entries as $entry) {
            $shippingDetails[] = $this->buildShippingDetail($entry, $currency);
        }

        return [
            '@type'           => 'Offer',
            '@id'             => $url . '#offer-multiregion-shipping',
            'shippingDetails' => count($shippingDetails) === 1
                ? $shippingDetails[0]
                : $shippingDetails,
        ];
    }

    /**
     * Collect shipping entries from all available Magento shipping sources.
     *
     * @return list<array{label: string, country: string, cost: string, transitMin: int, transitMax: int}>
     */
    private function collectShippingEntries(): array
    {
        $entries = [];

        // 1. Try table-rate entries.
        $tableRates = $this->getTableRateEntries();
        if ($tableRates !== []) {
            return $tableRates;
        }

        // 2. Fall back to flat-rate config.
        $flatRates = $this->getFlatRateEntries();
        if ($flatRates !== []) {
            return $flatRates;
        }

        return $entries;
    }

    /**
     * Read table-rate shipping entries for the current website.
     *
     * @return list<array{label: string, country: string, cost: string, transitMin: int, transitMax: int}>
     */
    private function getTableRateEntries(): array
    {
        if ($this->tablerateCollectionFactory === null) {
            return [];
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
        } catch (\Throwable) {
            return [];
        }

        $isActive = $this->scopeConfig->isSetFlag(
            self::XML_TABLERATE_ACTIVE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if (!$isActive) {
            return [];
        }

        try {
            /** @var \Magento\OfflineShipping\Model\ResourceModel\Carrier\Tablerate\Collection $collection */
            $collection = $this->tablerateCollectionFactory->create();
            $collection->addFieldToFilter('website_id', $websiteId);
            $collection->setOrder('dest_country_id', 'ASC');

            // Limit to prevent excessive schema on stores with thousands of rates.
            $collection->setPageSize(50);

            $entries = [];
            $seen = [];
            foreach ($collection as $rate) {
                $country = (string) ($rate->getData('dest_country_id') ?? '*');
                $price = (float) ($rate->getData('price') ?? 0.0);

                // Deduplicate: keep lowest cost per country.
                $key = $country;
                if (isset($seen[$key]) && $seen[$key] <= $price) {
                    continue;
                }
                $seen[$key] = $price;

                $entries[$key] = [
                    'label'      => 'Shipping to ' . ($country === '*' ? 'Worldwide' : $country),
                    'country'    => $country === '*' ? '' : $country,
                    'cost'       => number_format($price, 2, '.', ''),
                    'transitMin' => self::DEFAULT_TRANSIT_MIN,
                    'transitMax' => self::DEFAULT_TRANSIT_MAX,
                ];
            }

            return array_values($entries);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Build flat-rate shipping entries using Magento's flat-rate carrier config.
     *
     * @return list<array{label: string, country: string, cost: string, transitMin: int, transitMax: int}>
     */
    private function getFlatRateEntries(): array
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            $storeId = null;
        }

        $isActive = $this->scopeConfig->isSetFlag(
            self::XML_FLATRATE_ACTIVE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if (!$isActive) {
            return [];
        }

        $price = (float) ($this->scopeConfig->getValue(
            self::XML_FLATRATE_PRICE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?? 0.0);

        $title = (string) ($this->scopeConfig->getValue(
            self::XML_FLATRATE_TITLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?? '');

        $name = (string) ($this->scopeConfig->getValue(
            self::XML_FLATRATE_NAME,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?? '');

        $label = trim($title . ($name !== '' ? ' - ' . $name : ''));
        if ($label === '') {
            $label = 'Flat Rate Shipping';
        }

        $country = $this->getStoreCountry($storeId);

        return [
            [
                'label'      => $label,
                'country'    => $country,
                'cost'       => number_format($price, 2, '.', ''),
                'transitMin' => self::DEFAULT_TRANSIT_MIN,
                'transitMax' => self::DEFAULT_TRANSIT_MAX,
            ],
        ];
    }

    /**
     * Build a single OfferShippingDetails JSON-LD node.
     *
     * @param array{label: string, country: string, cost: string, transitMin: int, transitMax: int} $entry
     * @return array<string,mixed>
     */
    private function buildShippingDetail(array $entry, string $currency): array
    {
        $node = [
            '@type'        => 'OfferShippingDetails',
            'shippingRate' => [
                '@type'    => 'MonetaryAmount',
                'value'    => $entry['cost'],
                'currency' => $currency,
            ],
            'deliveryTime' => [
                '@type'       => 'ShippingDeliveryTime',
                'handlingTime' => [
                    '@type'    => 'QuantitativeValue',
                    'minValue' => $this->getHandlingTimeMin(),
                    'maxValue' => $this->getHandlingTimeMax(),
                    'unitCode' => 'DAY',
                ],
                'transitTime' => [
                    '@type'    => 'QuantitativeValue',
                    'minValue' => $entry['transitMin'],
                    'maxValue' => $entry['transitMax'],
                    'unitCode' => 'DAY',
                ],
                'businessDays' => [
                    '@type'     => 'OpeningHoursSpecification',
                    'dayOfWeek' => [
                        'https://schema.org/Monday',
                        'https://schema.org/Tuesday',
                        'https://schema.org/Wednesday',
                        'https://schema.org/Thursday',
                        'https://schema.org/Friday',
                    ],
                ],
            ],
            'shippingLabel' => $entry['label'],
        ];

        if ($entry['country'] !== '') {
            $node['shippingDestination'] = [
                '@type'          => 'DefinedRegion',
                'addressCountry' => $entry['country'],
            ];
        }

        return $node;
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

    private function getHandlingTimeMin(): int
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            $storeId = null;
        }

        $value = $this->scopeConfig->getValue(
            self::XML_HANDLING_TIME_MIN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value !== null ? max(0, (int) $value) : self::DEFAULT_HANDLING_MIN;
    }

    private function getHandlingTimeMax(): int
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            $storeId = null;
        }

        $value = $this->scopeConfig->getValue(
            self::XML_HANDLING_TIME_MAX,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value !== null ? max(0, (int) $value) : self::DEFAULT_HANDLING_MAX;
    }
}
