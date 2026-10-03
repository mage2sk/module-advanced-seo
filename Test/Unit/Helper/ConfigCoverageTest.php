<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigCoverageTest extends TestCase
{
    private array $calls = [];

    private function config(array $values = [], array $flags = [], ?EncryptorInterface $encryptor = null): Config
    {
        $this->calls = [];
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scope = 'default', $storeId = null) use ($values) {
                $this->calls[] = ['value', $path, $scope, $storeId];
                return $values[$path] ?? null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            function (string $path, string $scope = 'default', $storeId = null) use ($flags): bool {
                $this->calls[] = ['flag', $path, $scope, $storeId];
                return (bool) ($flags[$path] ?? false);
            }
        );

        return new Config($scopeConfig, $encryptor ?? $this->createStub(EncryptorInterface::class));
    }

    public static function flagMethods(): array
    {
        return [
            ['isEnabled', Config::XML_GENERAL_ENABLED],
            ['isDebug', Config::XML_GENERAL_DEBUG],
            ['useTemplates', Config::XML_META_USE_TEMPLATES],
            ['isNoindexNoRoute', Config::XML_META_NOINDEX_NOROUTE],
            ['appendStoreName', Config::XML_META_APPEND_STORE],
            ['isCanonicalEnabled', Config::XML_CANONICAL_ENABLED],
            ['stripCanonicalQuery', Config::XML_CANONICAL_STRIP_QUERY],
            ['canonicalLowercaseHost', Config::XML_CANONICAL_LOWERCASE_HOST],
            ['canonicalRemoveTrailingSlash', Config::XML_CANONICAL_REMOVE_TRAILING],
            ['canonicalPaginatedToFirst', Config::XML_CANONICAL_PAGINATED_TO_FIRST],
            ['isAssociatedProductCanonical', Config::XML_CANONICAL_ASSOCIATED_PRODUCT],
            ['isCanonicalDisabledForNoindex', Config::XML_CANONICAL_DISABLE_FOR_NOINDEX],
            ['isStripTitlePrefixSuffix', Config::XML_META_STRIP_TITLE_PREFIX_SUFFIX],
            ['isSeoNameEnabled', Config::XML_META_SEO_NAME_ENABLED],
            ['isForceTemplateOverExisting', Config::XML_META_FORCE_TEMPLATE_OVER_EXISTING],
            ['isAsyncIndexing', Config::XML_ADV_ASYNC_INDEXING],
            ['isMviewEnabled', Config::XML_ADV_MVIEW_ENABLED],
            ['useShortCategoryUrl', Config::XML_CANONICAL_USE_SHORT_CATEGORY_URL],
            ['isAutoUrlKeyEnabled', Config::XML_URL_AUTO_URL_KEY_ENABLED],
            ['isAutoUrlKeyForExisting', Config::XML_URL_AUTO_URL_KEY_FOR_EXISTING],
            ['isCrawlAuditEnabled', Config::XML_REPORTS_CRAWL_AUDIT_ENABLED],
            ['crawlVerifiesTls', Config::XML_REPORTS_CRAWL_VERIFY_TLS],
            ['crawlFollowsFilteredUrls', Config::XML_REPORTS_CRAWL_FOLLOW_FILTERED],
            ['isSeoToolbarEnabled', Config::XML_REPORTS_TOOLBAR_ENABLED],
            ['isMerchantFeedEnabled', Config::XML_MERCHANT_FEED_ENABLED],
            ['isMerchantFeedIncludeOutOfStock', Config::XML_MERCHANT_FEED_INCLUDE_OOS],
            ['isGa4Enabled', Config::XML_GA4_ENABLED],
            ['isGa4EnhancedEcommerceEnabled', Config::XML_GA4_ENHANCED_ECOM],
            ['isSearchConsoleIndexingEnabled', Config::XML_SC_INDEXING_API_ENABLED],
            ['isLastModifiedHeaderEnabled', Config::XML_ADV_LAST_MODIFIED_HEADER],
            ['isSpeculationRulesEnabled', Config::XML_ADV_SPECULATION_RULES],
            ['isAuditExternalLinkCheckEnabled', Config::XML_AUDIT_CHECK_EXTERNAL_LINKS],
        ];
    }

    #[DataProvider('flagMethods')]
    public function testFlagReadsItsPathAtStoreScope(string $method, string $path): void
    {
        $this->assertFalse($this->config()->$method(3));
        $this->assertSame(['flag', $path, ScopeInterface::SCOPE_STORE, 3], $this->calls[0]);
        $this->assertTrue($this->config([], [$path => true])->$method(3));
    }

    public static function defaults(): array
    {
        return [
            ['getTitleMaxLength', 60],
            ['getDescriptionMaxLength', 160],
            ['getCrossDomainCanonicalStore', 0],
            ['getCanonicalIgnorePages', ''],
            ['getProductCanonicalType', 'without_category'],
            ['getPaginationPosition', 'suffix'],
            ['getPaginationFormat', '| Page %p'],
            ['getTrailingSlashHomepage', 'none'],
            ['getUrlKeyTemplate', '{{name}}'],
            ['getCrawlExcludePaths', ''],
            ['getCrawlDepth', 100],
            ['getSeoToolbarAllowedIps', ''],
            ['getMerchantFeedDefaultCondition', 'new'],
            ['getMerchantFeedGoogleCategoryAttribute', ''],
            ['getMerchantFeedShippingCountry', ''],
            ['getMerchantFeedShippingPrice', ''],
            ['getGa4MeasurementId', ''],
            ['getSearchConsoleServiceAccountJson', ''],
            ['getSearchConsoleVerificationCode', ''],
            ['getCanonicalStripParams', ''],
            ['getDefaultBrand', ''],
            ['getAuditMaxExternalChecks', 500],
            ['getAuditSitemapSample', 300],
            ['getAuditSlowPageSeconds', 3.0],
            ['getAuditTimingSample', 50],
            ['getAuditHistoryRuns', 5],
        ];
    }

    #[DataProvider('defaults')]
    public function testUnsetValuesFallBackToDefaults(string $method, mixed $expected): void
    {
        $this->assertSame($expected, $this->config()->$method(1));
    }

    public static function configured(): array
    {
        return [
            ['getTitleMaxLength', Config::XML_META_TITLE_MAX, '70', 70],
            ['getDescriptionMaxLength', Config::XML_META_DESC_MAX, '155', 155],
            ['getCrossDomainCanonicalStore', Config::XML_CANONICAL_CROSS_DOMAIN_STORE, '2', 2],
            ['getCanonicalIgnorePages', Config::XML_CANONICAL_IGNORE_PAGES, 'checkout', 'checkout'],
            ['getProductCanonicalType', Config::XML_CANONICAL_PRODUCT_CANONICAL_TYPE, 'longest', 'longest'],
            ['getPaginationPosition', Config::XML_META_PAGINATION_POSITION, 'prefix', 'prefix'],
            ['getPaginationFormat', Config::XML_META_PAGINATION_FORMAT, 'P%p', 'P%p'],
            ['getTrailingSlashHomepage', Config::XML_CANONICAL_TRAILING_SLASH_HOMEPAGE, 'add', 'add'],
            ['getUrlKeyTemplate', Config::XML_URL_URL_KEY_TEMPLATE, '{{sku}}', '{{sku}}'],
            ['getCrawlExcludePaths', Config::XML_REPORTS_CRAWL_EXCLUDE_PATHS, '/a', '/a'],
            ['getCrawlDepth', Config::XML_REPORTS_CRAWL_DEPTH, '7', 7],
            ['getSeoToolbarAllowedIps', Config::XML_REPORTS_TOOLBAR_ALLOWED_IPS, '1.2.3.4', '1.2.3.4'],
            ['getGa4MeasurementId', Config::XML_GA4_MEASUREMENT_ID, '  G-ABC  ', 'G-ABC'],
            ['getSearchConsoleVerificationCode', Config::XML_SC_SITE_VERIFICATION_CODE, ' code ', 'code'],
            ['getCanonicalStripParams', Config::XML_CANONICAL_STRIP_PARAMS, 'utm_*', 'utm_*'],
            ['getAuditMaxExternalChecks', Config::XML_AUDIT_MAX_EXTERNAL_CHECKS, '-4', 0],
            ['getAuditMaxExternalChecks', Config::XML_AUDIT_MAX_EXTERNAL_CHECKS, '20', 20],
            ['getAuditSitemapSample', Config::XML_AUDIT_SITEMAP_SAMPLE, '0', 1],
            ['getAuditSlowPageSeconds', Config::XML_AUDIT_SLOW_PAGE_SECONDS, '0', 3.0],
            ['getAuditSlowPageSeconds', Config::XML_AUDIT_SLOW_PAGE_SECONDS, '1.5', 1.5],
            ['getAuditTimingSample', Config::XML_AUDIT_TIMING_SAMPLE, '-1', 0],
            ['getAuditHistoryRuns', Config::XML_AUDIT_HISTORY_RUNS, '1', 2],
            ['getAuditHistoryRuns', Config::XML_AUDIT_HISTORY_RUNS, '9', 9],
            ['getDefaultBrand', Config::XML_SD_DEFAULT_BRAND, ' Acme ', 'Acme'],
        ];
    }

    #[DataProvider('configured')]
    public function testConfiguredValuesAreCastAndClamped(string $method, string $path, string $raw, mixed $expected): void
    {
        $this->assertSame($expected, $this->config([$path => $raw])->$method(1));
    }

    public static function conditions(): array
    {
        return [
            ['new', 'new'],
            ['used', 'used'],
            ['refurbished', 'refurbished'],
            ['damaged', 'new'],
            ['', 'new'],
        ];
    }

    #[DataProvider('conditions')]
    public function testMerchantFeedConditionIsWhitelisted(string $raw, string $expected): void
    {
        $config = $this->config([Config::XML_MERCHANT_FEED_DEFAULT_CONDITION => $raw]);

        $this->assertSame($expected, $config->getMerchantFeedDefaultCondition());
    }

    public static function categoryAttributes(): array
    {
        return [
            ['google_category', 'google_category'],
            ['  gcat  ', 'gcat'],
            ['Google', ''],
            ['1abc', ''],
            ['bad-code', ''],
            ['a' . str_repeat('b', 60), ''],
        ];
    }

    #[DataProvider('categoryAttributes')]
    public function testGoogleCategoryAttributeMustBeAValidAttributeCode(string $raw, string $expected): void
    {
        $config = $this->config([Config::XML_MERCHANT_FEED_GOOGLE_CAT_ATTRIBUTE => $raw]);

        $this->assertSame($expected, $config->getMerchantFeedGoogleCategoryAttribute());
    }

    public static function countries(): array
    {
        return [
            ['gb', 'GB'],
            [' us ', 'US'],
            ['GBR', ''],
            ['g1', ''],
        ];
    }

    #[DataProvider('countries')]
    public function testShippingCountryIsATwoLetterUppercaseCode(string $raw, string $expected): void
    {
        $config = $this->config([Config::XML_MERCHANT_FEED_SHIPPING_COUNTRY => $raw]);

        $this->assertSame($expected, $config->getMerchantFeedShippingCountry());
    }

    public static function prices(): array
    {
        return [
            ['4.5', '4.50'],
            ['0', '0.00'],
            ['1234.567', '1234.57'],
            ['-1', ''],
            ['free', ''],
        ];
    }

    #[DataProvider('prices')]
    public function testShippingPriceIsANonNegativeTwoDecimalNumber(string $raw, string $expected): void
    {
        $config = $this->config([Config::XML_MERCHANT_FEED_SHIPPING_PRICE => $raw]);

        $this->assertSame($expected, $config->getMerchantFeedShippingPrice());
    }

    public function testServiceAccountJsonIsDecrypted(): void
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(static fn(string $v): string => 'plain:' . $v);

        $config = $this->config([Config::XML_SC_SERVICE_ACCOUNT_JSON => 'cipher'], [], $encryptor);

        $this->assertSame('plain:cipher', $config->getSearchConsoleServiceAccountJson(1));
    }

    public function testDefaultBrandUsesTheLegacyPathWhenTheCurrentOneIsBlank(): void
    {
        $config = $this->config([
            Config::XML_SD_DEFAULT_BRAND        => '   ',
            Config::XML_SD_DEFAULT_BRAND_LEGACY => ' Old ',
        ]);

        $this->assertSame('Old', $config->getDefaultBrand());
    }

    public function testGetValueProxiesTheRawStoreValue(): void
    {
        $config = $this->config(['some/path' => ['x' => 1]]);

        $this->assertSame(['x' => 1], $config->getValue('some/path', 4));
        $this->assertSame(['value', 'some/path', ScopeInterface::SCOPE_STORE, 4], $this->calls[0]);
    }

    public function testAuditFixFlagUsesTheFixesPrefix(): void
    {
        $path = Config::XML_AUDIT_FIXES_PREFIX . Config::AUDIT_FIX_HREFLANG_GROUP_GUARD;
        $config = $this->config([], [$path => true]);

        $this->assertTrue($config->isAuditFixEnabled(Config::AUDIT_FIX_HREFLANG_GROUP_GUARD));
        $this->assertFalse($config->isAuditFixEnabled(Config::AUDIT_FIX_SEO_TITLE_WINS_OVER_H1));
    }

    public function testStructuredDataFlagCodesMapToTheirConfigKeys(): void
    {
        $config = $this->config([], [
            'panth_seo/structured_data/configurable_multi_offer' => true,
            'panth_seo/structured_data/product'                  => true,
            'panth_seo/structured_data/organization'             => true,
        ]);

        $this->assertTrue($config->isStructuredDataEnabled('configurable_offer'));
        $this->assertTrue($config->isStructuredDataEnabled('bundle_offer'));
        $this->assertTrue($config->isStructuredDataEnabled('grouped_offer'));
        $this->assertTrue($config->isStructuredDataEnabled('organization'));
        $this->assertFalse($config->isStructuredDataEnabled('productList'));
        $this->assertFalse($config->isStructuredDataEnabled('pros_cons'));
    }

    public static function valueCodes(): array
    {
        return [
            'return policy days' => ['return_policy', 'panth_seo/structured_data/return_policy_days'],
            'delivery methods'   => ['deliveryMethod', 'panth_seo/structured_data/delivery_methods'],
            'payment methods'    => ['paymentMethod', 'panth_seo/structured_data/accepted_payment_methods'],
            'custom properties'  => ['custom_properties', 'panth_seo/structured_data/custom_properties'],
            'multi region'       => ['multiRegionShipping', 'panth_seo/structured_data/delivery_methods'],
        ];
    }

    #[DataProvider('valueCodes')]
    public function testValueBackedStructuredDataCodesNeedANonEmptyNonZeroValue(string $code, string $path): void
    {
        $this->assertTrue($this->config([$path => '30'])->isStructuredDataEnabled($code, 1));
        $this->assertFalse($this->config([$path => '0'])->isStructuredDataEnabled($code, 1));
        $this->assertFalse($this->config([$path => ''])->isStructuredDataEnabled($code, 1));
        $this->assertFalse($this->config()->isStructuredDataEnabled($code, 1));
    }

    public function testProductConditionSchemaUrlMapsTheFeedDefaultCondition(): void
    {
        $path = Config::XML_MERCHANT_FEED_DEFAULT_CONDITION;
        $this->assertSame('https://schema.org/NewCondition', $this->config()->getProductConditionSchemaUrl(1));
        $this->assertSame('https://schema.org/UsedCondition', $this->config([$path => 'used'])->getProductConditionSchemaUrl(1));
        $this->assertSame(
            'https://schema.org/RefurbishedCondition',
            $this->config([$path => 'refurbished'])->getProductConditionSchemaUrl(1)
        );
        $this->assertSame('https://schema.org/NewCondition', $this->config([$path => 'bogus'])->getProductConditionSchemaUrl(1));
    }
}
