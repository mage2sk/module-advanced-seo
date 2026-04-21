<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed accessor for every `panth_seo/*` system.xml field.
 */
class Config
{
    public const XML_GENERAL_ENABLED     = 'panth_seo/general/enabled';
    public const XML_GENERAL_DEBUG       = 'panth_seo/general/debug';

    public const XML_META_USE_TEMPLATES  = 'panth_seo/meta/use_templates';
    public const XML_META_TITLE_MAX      = 'panth_seo/meta/title_max_length';
    public const XML_META_DESC_MAX       = 'panth_seo/meta/description_max_length';
    public const XML_META_APPEND_STORE              = 'panth_seo/meta/append_store_name';
    public const XML_META_FORCE_TEMPLATE_OVER_EXISTING = 'panth_seo/meta/force_template_over_existing';

    public const XML_CANONICAL_ENABLED           = 'panth_seo/canonical/enabled';
    public const XML_CANONICAL_STRIP_QUERY       = 'panth_seo/canonical/strip_query';
    public const XML_CANONICAL_LOWERCASE_HOST    = 'panth_seo/canonical/lowercase_host';
    public const XML_CANONICAL_REMOVE_TRAILING   = 'panth_seo/canonical/remove_trailing_slash';
    public const XML_CANONICAL_PAGINATED_TO_FIRST = 'panth_seo/canonical/paginated_canonical_to_first';

    public const XML_SD_PRODUCT      = 'panth_seo/structured_data/product';
    public const XML_SD_BREADCRUMB   = 'panth_seo/structured_data/breadcrumb';
    public const XML_SD_ORGANIZATION = 'panth_seo/structured_data/organization';
    public const XML_SD_WEBSITE      = 'panth_seo/structured_data/website';
    public const XML_SD_FAQ          = 'panth_seo/structured_data/faq';
    public const XML_SD_ARTICLE      = 'panth_seo/structured_data/article';

    public const XML_SITEMAP_ENABLED          = 'panth_seo/sitemap/enabled';
    public const XML_SITEMAP_SHARD_SIZE       = 'panth_seo/sitemap/shard_size';
    public const XML_SITEMAP_GZIP             = 'panth_seo/sitemap/gzip';
    public const XML_SITEMAP_INCLUDE_IMAGES   = 'panth_seo/sitemap/include_images';
    public const XML_SITEMAP_INCLUDE_HREFLANG = 'panth_seo/sitemap/include_hreflang';

    public const XML_HREFLANG_ENABLED  = 'panth_seo/hreflang/enabled';
    public const XML_HREFLANG_XDEFAULT = 'panth_seo/hreflang/emit_x_default';

    // Social / Open Graph
    public const XML_SOCIAL_OG_ENABLED        = 'panth_seo/social/og_enabled';
    public const XML_SOCIAL_TWITTER_ENABLED   = 'panth_seo/social/twitter_enabled';
    public const XML_SOCIAL_TWITTER_CARD_TYPE = 'panth_seo/social/twitter_card_type';
    public const XML_SOCIAL_TWITTER_HANDLE    = 'panth_seo/social/twitter_site_handle';
    public const XML_SOCIAL_DEFAULT_OG_IMAGE  = 'panth_seo/social/default_og_image';

    // Canonical (new)
    public const XML_CANONICAL_ASSOCIATED_PRODUCT  = 'panth_seo/canonical/associated_product_canonical';
    public const XML_CANONICAL_CROSS_DOMAIN_STORE  = 'panth_seo/canonical/cross_domain_store';
    public const XML_CANONICAL_IGNORE_PAGES        = 'panth_seo/canonical/canonical_ignore_pages';
    public const XML_CANONICAL_DISABLE_FOR_NOINDEX = 'panth_seo/canonical/disable_canonical_for_noindex';

    // Structured Data (new)
    public const XML_SD_CONFIGURABLE_MULTI_OFFER = 'panth_seo/structured_data/configurable_multi_offer';
    public const XML_SD_REMOVE_NATIVE_MARKUP     = 'panth_seo/structured_data/remove_native_markup';
    public const XML_SD_RETURN_POLICY_DAYS       = 'panth_seo/structured_data/return_policy_days';
    public const XML_SD_BRAND_ATTRIBUTE          = 'panth_seo/structured_data/brand_attribute';
    public const XML_SD_GTIN_ATTRIBUTE           = 'panth_seo/structured_data/gtin_attribute';
    public const XML_SD_MPN_ATTRIBUTE            = 'panth_seo/structured_data/mpn_attribute';

    // Filter URLs
    public const XML_FILTER_URL_ENABLED   = 'panth_seo/filter_urls/filter_urls_enabled';
    public const XML_FILTER_URL_FORMAT    = 'panth_seo/filter_urls/url_format';
    public const XML_FILTER_URL_SEPARATOR = 'panth_seo/filter_urls/separator';

    // Filter Meta
    public const XML_FILTER_META_ENABLED            = 'panth_seo/filter_meta/filter_meta_enabled';
    public const XML_FILTER_META_INJECT_TITLE       = 'panth_seo/filter_meta/inject_filter_in_title';
    public const XML_FILTER_META_INJECT_DESCRIPTION = 'panth_seo/filter_meta/inject_filter_in_description';

    // Sitemap (new)
    public const XML_SITEMAP_EXCLUDE_OOS     = 'panth_seo/sitemap/exclude_out_of_stock';
    public const XML_SITEMAP_EXCLUDE_NOINDEX = 'panth_seo/sitemap/exclude_noindex';
    public const XML_SITEMAP_ADDITIONAL      = 'panth_seo/sitemap/additional_links';
    public const XML_SITEMAP_XSL_ENABLED     = 'panth_seo/sitemap/xsl_enabled';

    // Meta (new)
    public const XML_META_STRIP_TITLE_PREFIX_SUFFIX = 'panth_seo/meta/strip_title_prefix_suffix';
    public const XML_META_SEO_NAME_ENABLED          = 'panth_seo/meta/seo_name_enabled';
    public const XML_META_PAGINATION_POSITION       = 'panth_seo/meta/pagination_position';
    public const XML_META_PAGINATION_FORMAT          = 'panth_seo/meta/pagination_format';

    // Canonical (product canonical type)
    public const XML_CANONICAL_PRODUCT_CANONICAL_TYPE = 'panth_seo/canonical/product_canonical_type';

    // Canonical (short category URL)
    public const XML_CANONICAL_USE_SHORT_CATEGORY_URL = 'panth_seo/canonical/use_short_category_url';

    // Canonical (trailing slash homepage)
    public const XML_CANONICAL_TRAILING_SLASH_HOMEPAGE = 'panth_seo/canonical/trailing_slash_homepage';

    // Hreflang (new)
    public const XML_HREFLANG_SCOPE              = 'panth_seo/hreflang/hreflang_scope';
    public const XML_HREFLANG_CMS_RELATION       = 'panth_seo/hreflang/cms_relation_method';

    // Sitemap (new pings + additional link settings)
    public const XML_SITEMAP_PING_GOOGLE              = 'panth_seo/sitemap/ping_google';
    public const XML_SITEMAP_PING_BING                = 'panth_seo/sitemap/ping_bing';
    public const XML_SITEMAP_ADDITIONAL_LINKS_FREQ    = 'panth_seo/sitemap/additional_links_changefreq';
    public const XML_SITEMAP_ADDITIONAL_LINKS_PRIORITY = 'panth_seo/sitemap/additional_links_priority';

    // Structured Data (new)
    public const XML_SD_PRODUCT_LIST_SCHEMA       = 'panth_seo/structured_data/enable_product_list_schema';
    public const XML_SD_ACCEPTED_PAYMENT          = 'panth_seo/structured_data/accepted_payment_methods';
    public const XML_SD_DELIVERY_METHODS          = 'panth_seo/structured_data/delivery_methods';
    public const XML_SD_PRODUCT_CONDITION         = 'panth_seo/structured_data/product_condition';
    public const XML_SD_PRICE_VALID_UNTIL_DEFAULT = 'panth_seo/structured_data/price_valid_until_default';
    public const XML_SD_CUSTOM_PROPERTIES         = 'panth_seo/structured_data/custom_properties';
    public const XML_SD_PRODUCT_GROUP_ENABLED     = 'panth_seo/structured_data/product_group_enabled';
    public const XML_SD_PROS_CONS_ENABLED         = 'panth_seo/structured_data/pros_cons_enabled';
    public const XML_SD_PROS_ATTRIBUTE            = 'panth_seo/structured_data/pros_attribute';
    public const XML_SD_CONS_ATTRIBUTE            = 'panth_seo/structured_data/cons_attribute';

    // Sitemap (homepage & image source)
    public const XML_SITEMAP_HOMEPAGE_OPTIMIZATION = 'panth_seo/sitemap/homepage_optimization';
    public const XML_SITEMAP_PRODUCT_IMAGE_SOURCE  = 'panth_seo/sitemap/product_image_source';

    // URL Key Automation
    public const XML_URL_AUTO_URL_KEY_ENABLED      = 'panth_seo/url/auto_url_key_enabled';
    public const XML_URL_URL_KEY_TEMPLATE          = 'panth_seo/url/url_key_template';
    public const XML_URL_AUTO_URL_KEY_FOR_EXISTING = 'panth_seo/url/auto_url_key_for_existing';

    // Reports & Diagnostics
    public const XML_REPORTS_CRAWL_AUDIT_ENABLED = 'panth_seo/reports/enable_crawl_audit';
    public const XML_REPORTS_CRAWL_DEPTH         = 'panth_seo/reports/crawl_depth';
    public const XML_REPORTS_TOOLBAR_ENABLED      = 'panth_seo/reports/seo_toolbar_enabled';
    public const XML_REPORTS_TOOLBAR_ALLOWED_IPS  = 'panth_seo/reports/seo_toolbar_allowed_ips';

    // Breadcrumbs
    public const XML_BREADCRUMBS_FORMAT          = 'panth_seo/breadcrumbs/breadcrumb_format';
    public const XML_BREADCRUMBS_PRIORITY_ENABLED = 'panth_seo/breadcrumbs/enable_breadcrumb_priority';

    // Image SEO
    public const XML_IMAGE_SEO_ENABLED    = 'panth_seo/image/image_seo_enabled';
    public const XML_IMAGE_ALT_TEMPLATE   = 'panth_seo/image/alt_template';
    public const XML_IMAGE_TITLE_TEMPLATE = 'panth_seo/image/title_template';
    public const XML_IMAGE_GALLERY_ENABLED = 'panth_seo/image/gallery_seo_enabled';

    // IndexNow Protocol
    public const XML_INDEXNOW_ENABLED = 'panth_seo/indexnow/enabled';
    public const XML_INDEXNOW_API_KEY = 'panth_seo/indexnow/api_key';

    public const XML_ADV_ASYNC_INDEXING          = 'panth_seo/advanced/async_indexing';
    public const XML_ADV_MVIEW_ENABLED           = 'panth_seo/advanced/mview_enabled';
    public const XML_ADV_LAST_MODIFIED_HEADER    = 'panth_seo/advanced/last_modified_header';
    public const XML_ADV_SPECULATION_RULES       = 'panth_seo/advanced/speculation_rules_enabled';

    // Canonical (strip params)
    public const XML_CANONICAL_STRIP_PARAMS = 'panth_seo/canonical/strip_params';

    // Sitemap (video)
    public const XML_SITEMAP_INCLUDE_VIDEO = 'panth_seo/sitemap/include_video';

    // LLMs.txt
    public const XML_LLMS_TXT_ENABLED        = 'panth_seo/llms_txt/enabled';
    public const XML_LLMS_TXT_SUMMARY        = 'panth_seo/llms_txt/summary';
    public const XML_LLMS_TXT_MAX_CATEGORIES = 'panth_seo/llms_txt/max_categories';
    public const XML_LLMS_TXT_MAX_PRODUCTS   = 'panth_seo/llms_txt/max_products';
    public const XML_LLMS_TXT_MAX_CMS        = 'panth_seo/llms_txt/max_cms';
    public const XML_LLMS_TXT_GENERATE_FULL  = 'panth_seo/llms_txt/generate_full_llms';
    public const XML_LLMS_TXT_SHIPPING_PAGE  = 'panth_seo/llms_txt/shipping_page';
    public const XML_LLMS_TXT_RETURNS_PAGE   = 'panth_seo/llms_txt/returns_page';
    public const XML_LLMS_TXT_ABOUT_PAGE     = 'panth_seo/llms_txt/about_page';
    public const XML_LLMS_TXT_FAQ_PAGE       = 'panth_seo/llms_txt/faq_page';

    // Organization Details
    public const XML_ORG_LEGAL_NAME = 'panth_seo/organization/legal_name';
    public const XML_ORG_LOGO       = 'panth_seo/organization/logo';
    public const XML_ORG_PHONE      = 'panth_seo/organization/phone';
    public const XML_ORG_EMAIL      = 'panth_seo/organization/email';
    public const XML_ORG_STREET     = 'panth_seo/organization/street';
    public const XML_ORG_LOCALITY   = 'panth_seo/organization/locality';
    public const XML_ORG_REGION     = 'panth_seo/organization/region';
    public const XML_ORG_POSTCODE   = 'panth_seo/organization/postcode';
    public const XML_ORG_COUNTRY    = 'panth_seo/organization/country';
    public const XML_ORG_SAME_AS    = 'panth_seo/organization/same_as';

    // Structured Data (additional)
    public const XML_SD_BUSINESS_TYPE      = 'panth_seo/structured_data/business_type';
    public const XML_SD_DEFAULT_BRAND      = 'panth_seo/structured_data/default_brand';
    public const XML_SD_RETURN_POLICY_TYPE = 'panth_seo/structured_data/return_policy_type';
    public const XML_SD_RETURN_POLICY_FEES = 'panth_seo/structured_data/return_policy_fees';
    public const XML_SD_ENERGY_LABEL_ENABLED  = 'panth_seo/structured_data/energy_label_enabled';
    public const XML_SD_ENERGY_CLASS_ATTRIBUTE = 'panth_seo/structured_data/energy_class_attribute';
    public const XML_SD_CERTIFICATION_ENABLED  = 'panth_seo/structured_data/certification_enabled';
    public const XML_SD_CERTIFICATION_ATTRIBUTE = 'panth_seo/structured_data/certification_attribute';
    public const XML_SD_SALE_EVENT_ENABLED     = 'panth_seo/structured_data/sale_event_enabled';
    public const XML_SD_LIMITED_STOCK_THRESHOLD = 'panth_seo/structured_data/limited_stock_threshold';

    // Google Analytics 4
    public const XML_GA4_ENABLED        = 'panth_seo/analytics/ga4_enabled';
    public const XML_GA4_MEASUREMENT_ID = 'panth_seo/analytics/ga4_measurement_id';
    public const XML_GA4_ENHANCED_ECOM  = 'panth_seo/analytics/ga4_enhanced_ecommerce';

    // Google Search Console
    public const XML_SC_INDEXING_API_ENABLED     = 'panth_seo/search_console/indexing_api_enabled';
    public const XML_SC_SERVICE_ACCOUNT_JSON     = 'panth_seo/search_console/service_account_json';
    public const XML_SC_SITE_VERIFICATION_CODE   = 'panth_seo/search_console/site_verification_code';

    // Google Merchant Center Feed
    public const XML_MERCHANT_FEED_ENABLED              = 'panth_seo/merchant_feed/enabled';
    public const XML_MERCHANT_FEED_INCLUDE_OOS          = 'panth_seo/merchant_feed/include_out_of_stock';
    public const XML_MERCHANT_FEED_DEFAULT_CONDITION     = 'panth_seo/merchant_feed/default_condition';
    public const XML_MERCHANT_FEED_GOOGLE_CAT_ATTRIBUTE = 'panth_seo/merchant_feed/google_category_attribute';
    public const XML_MERCHANT_FEED_SHIPPING_COUNTRY     = 'panth_seo/merchant_feed/shipping_country';
    public const XML_MERCHANT_FEED_SHIPPING_PRICE       = 'panth_seo/merchant_feed/shipping_price';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_GENERAL_ENABLED, $storeId);
    }

    public function isDebug(?int $storeId = null): bool
    {
        return $this->flag(self::XML_GENERAL_DEBUG, $storeId);
    }

    public function useTemplates(?int $storeId = null): bool
    {
        return $this->flag(self::XML_META_USE_TEMPLATES, $storeId);
    }

    public function getTitleMaxLength(?int $storeId = null): int
    {
        return (int) ($this->value(self::XML_META_TITLE_MAX, $storeId) ?? 60);
    }

    public function getDescriptionMaxLength(?int $storeId = null): int
    {
        return (int) ($this->value(self::XML_META_DESC_MAX, $storeId) ?? 160);
    }

    public function appendStoreName(?int $storeId = null): bool
    {
        return $this->flag(self::XML_META_APPEND_STORE, $storeId);
    }

    public function isCanonicalEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_CANONICAL_ENABLED, $storeId);
    }

    public function stripCanonicalQuery(?int $storeId = null): bool
    {
        return $this->flag(self::XML_CANONICAL_STRIP_QUERY, $storeId);
    }

    public function canonicalLowercaseHost(?int $storeId = null): bool
    {
        return $this->flag(self::XML_CANONICAL_LOWERCASE_HOST, $storeId);
    }

    public function canonicalRemoveTrailingSlash(?int $storeId = null): bool
    {
        return $this->flag(self::XML_CANONICAL_REMOVE_TRAILING, $storeId);
    }

    public function canonicalPaginatedToFirst(?int $storeId = null): bool
    {
        return $this->flag(self::XML_CANONICAL_PAGINATED_TO_FIRST, $storeId);
    }

    public function isStructuredDataEnabled(string $code, ?int $storeId = null): bool
    {
        // Map provider codes to their actual config.xml field names when they differ.
        // Without this mapping, providers whose getCode() doesn't match a boolean
        // config field under panth_seo/structured_data/ are silently disabled.
        static $codeToConfigKey = [
            'return_policy'      => 'return_policy_days',   // days > 0 acts as enable flag
            'configurable_offer' => 'configurable_multi_offer',
            'productList'        => 'enable_product_list_schema',
            'product_group'      => 'product_group_enabled',
            'pros_cons'          => 'pros_cons_enabled',
            'bundle_offer'       => 'product',              // enabled alongside core product schema
            'grouped_offer'      => 'product',              // enabled alongside core product schema
            'deliveryMethod'     => 'delivery_methods',     // non-empty string acts as enable
            'paymentMethod'      => 'accepted_payment_methods', // non-empty string acts as enable
            'custom_properties'  => 'custom_properties',    // non-empty string acts as enable
            'multiRegionShipping' => 'delivery_methods',    // shares delivery_methods toggle
        ];

        $configKey = $codeToConfigKey[$code] ?? $code;
        $path = 'panth_seo/structured_data/' . $configKey;

        // For numeric/text fields that act as implicit toggles, check non-empty value
        // instead of boolean flag (e.g. return_policy_days=30 should be truthy).
        if (in_array($code, ['return_policy', 'deliveryMethod', 'paymentMethod', 'custom_properties', 'multiRegionShipping'], true)) {
            $val = $this->value($path, $storeId);
            return $val !== null && $val !== '' && $val !== '0';
        }

        return $this->flag($path, $storeId);
    }

    public function isSitemapEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_ENABLED, $storeId);
    }

    public function getSitemapShardSize(?int $storeId = null): int
    {
        return max(1000, (int) ($this->value(self::XML_SITEMAP_SHARD_SIZE, $storeId) ?? 45000));
    }

    public function sitemapGzip(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_GZIP, $storeId);
    }

    public function sitemapIncludeImages(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_INCLUDE_IMAGES, $storeId);
    }

    public function sitemapIncludeHreflang(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_INCLUDE_HREFLANG, $storeId);
    }

    public function isHreflangEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_HREFLANG_ENABLED, $storeId);
    }

    public function emitHreflangXDefault(?int $storeId = null): bool
    {
        return $this->flag(self::XML_HREFLANG_XDEFAULT, $storeId);
    }

    // --- Social / Open Graph ---

    public function isOgEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SOCIAL_OG_ENABLED, $storeId);
    }

    public function isTwitterEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SOCIAL_TWITTER_ENABLED, $storeId);
    }

    public function getTwitterCardType(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SOCIAL_TWITTER_CARD_TYPE, $storeId) ?? 'summary_large_image');
    }

    public function getTwitterSiteHandle(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SOCIAL_TWITTER_HANDLE, $storeId) ?? '');
    }

    public function getDefaultOgImage(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SOCIAL_DEFAULT_OG_IMAGE, $storeId) ?? '');
    }

    // --- Canonical (new) ---

    public function isAssociatedProductCanonical(?int $storeId = null): bool
    {
        return $this->flag(self::XML_CANONICAL_ASSOCIATED_PRODUCT, $storeId);
    }

    public function getCrossDomainCanonicalStore(?int $storeId = null): int
    {
        return (int) ($this->value(self::XML_CANONICAL_CROSS_DOMAIN_STORE, $storeId) ?? 0);
    }

    public function getCanonicalIgnorePages(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_CANONICAL_IGNORE_PAGES, $storeId) ?? '');
    }

    public function isCanonicalDisabledForNoindex(?int $storeId = null): bool
    {
        return $this->flag(self::XML_CANONICAL_DISABLE_FOR_NOINDEX, $storeId);
    }

    public function getProductCanonicalType(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_CANONICAL_PRODUCT_CANONICAL_TYPE, $storeId) ?? 'without_category');
    }

    // --- Structured Data (new) ---

    public function isConfigurableMultiOffer(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SD_CONFIGURABLE_MULTI_OFFER, $storeId);
    }

    public function isRemoveNativeMarkup(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SD_REMOVE_NATIVE_MARKUP, $storeId);
    }

    public function getReturnPolicyDays(?int $storeId = null): int
    {
        return (int) ($this->value(self::XML_SD_RETURN_POLICY_DAYS, $storeId) ?? 30);
    }

    public function getBrandAttribute(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SD_BRAND_ATTRIBUTE, $storeId) ?? 'manufacturer');
    }

    public function getGtinAttribute(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SD_GTIN_ATTRIBUTE, $storeId) ?? '');
    }

    public function getMpnAttribute(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SD_MPN_ATTRIBUTE, $storeId) ?? '');
    }

    // --- Filter URLs ---

    public function isFilterUrlEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_FILTER_URL_ENABLED, $storeId);
    }

    public function getFilterUrlFormat(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_FILTER_URL_FORMAT, $storeId) ?? 'short');
    }

    public function getFilterUrlSeparator(?int $storeId = null): string
    {
        $val = (string) ($this->value(self::XML_FILTER_URL_SEPARATOR, $storeId) ?? '-');
        return $val !== '' ? $val : '-';
    }

    // --- Filter Meta ---

    public function isFilterMetaEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_FILTER_META_ENABLED, $storeId);
    }

    public function injectFilterInTitle(?int $storeId = null): bool
    {
        return $this->flag(self::XML_FILTER_META_INJECT_TITLE, $storeId);
    }

    public function injectFilterInDescription(?int $storeId = null): bool
    {
        return $this->flag(self::XML_FILTER_META_INJECT_DESCRIPTION, $storeId);
    }

    // --- Sitemap (new) ---

    public function sitemapExcludeOutOfStock(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_EXCLUDE_OOS, $storeId);
    }

    public function sitemapExcludeNoindex(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_EXCLUDE_NOINDEX, $storeId);
    }

    public function getSitemapAdditionalLinks(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SITEMAP_ADDITIONAL, $storeId) ?? '');
    }

    public function isSitemapXslEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_XSL_ENABLED, $storeId);
    }

    public function isSitemapHomepageOptimization(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_HOMEPAGE_OPTIMIZATION, $storeId);
    }

    public function getSitemapProductImageSource(?int $storeId = null): string
    {
        $value = (string) ($this->value(self::XML_SITEMAP_PRODUCT_IMAGE_SOURCE, $storeId) ?? 'base_image');
        $allowed = ['base_image', 'small_image', 'thumbnail'];
        return in_array($value, $allowed, true) ? $value : 'base_image';
    }

    // --- Meta (new) ---

    public function isStripTitlePrefixSuffix(?int $storeId = null): bool
    {
        return $this->flag(self::XML_META_STRIP_TITLE_PREFIX_SUFFIX, $storeId);
    }

    public function isSeoNameEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_META_SEO_NAME_ENABLED, $storeId);
    }

    /**
     * When enabled, meta templates override even when the entity already has
     * a manually set meta_title / meta_description.
     */
    public function isForceTemplateOverExisting(?int $storeId = null): bool
    {
        return $this->flag(self::XML_META_FORCE_TEMPLATE_OVER_EXISTING, $storeId);
    }

    /**
     * Generic config value accessor for paths handled by this helper.
     */
    public function getValue(string $path, ?int $storeId = null): mixed
    {
        return $this->value($path, $storeId);
    }

    public function isAsyncIndexing(?int $storeId = null): bool
    {
        return $this->flag(self::XML_ADV_ASYNC_INDEXING, $storeId);
    }

    public function isMviewEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_ADV_MVIEW_ENABLED, $storeId);
    }

    // --- Meta: Pagination ---

    public function getPaginationPosition(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_META_PAGINATION_POSITION, $storeId) ?? 'suffix');
    }

    public function getPaginationFormat(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_META_PAGINATION_FORMAT, $storeId) ?? '| Page %p');
    }

    // --- Canonical: Short Category URL ---

    public function useShortCategoryUrl(?int $storeId = null): bool
    {
        return $this->flag(self::XML_CANONICAL_USE_SHORT_CATEGORY_URL, $storeId);
    }

    // --- Canonical: Trailing Slash Homepage ---

    /**
     * Trailing slash policy for homepage canonical: "add", "remove", or "none".
     */
    public function getTrailingSlashHomepage(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_CANONICAL_TRAILING_SLASH_HOMEPAGE, $storeId) ?? 'none');
    }

    // --- Hreflang (new) ---

    public function getHreflangScope(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_HREFLANG_SCOPE, $storeId) ?? 'website');
    }

    public function getCmsRelationMethod(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_HREFLANG_CMS_RELATION, $storeId) ?? 'by_url_key');
    }

    // --- Sitemap: Ping ---

    public function isSitemapPingGoogleEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_PING_GOOGLE, $storeId);
    }

    public function isSitemapPingBingEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_PING_BING, $storeId);
    }

    public function getAdditionalLinksChangefreq(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SITEMAP_ADDITIONAL_LINKS_FREQ, $storeId) ?? 'weekly');
    }

    public function getAdditionalLinksPriority(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SITEMAP_ADDITIONAL_LINKS_PRIORITY, $storeId) ?? '0.5');
    }

    // --- Structured Data (new) ---

    public function isProductListSchemaEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SD_PRODUCT_LIST_SCHEMA, $storeId);
    }

    public function getAcceptedPaymentMethods(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SD_ACCEPTED_PAYMENT, $storeId) ?? '');
    }

    public function getDeliveryMethods(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SD_DELIVERY_METHODS, $storeId) ?? '');
    }

    /**
     * Parse the custom JSON-LD properties textarea into an associative array.
     *
     * Returns an empty array when the value is blank or contains invalid JSON.
     *
     * @return array<string, mixed>
     */
    public function getCustomSchemaProperties(?int $storeId = null): array
    {
        $raw = trim((string) ($this->value(self::XML_SD_CUSTOM_PROPERTIES, $storeId) ?? ''));
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Configured product condition key (new, used, refurbished, damaged).
     */
    public function getProductCondition(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SD_PRODUCT_CONDITION, $storeId) ?? 'new');
    }

    /**
     * Resolve the schema.org itemCondition URL for the configured product condition.
     */
    public function getProductConditionSchemaUrl(?int $storeId = null): string
    {
        $map = [
            'new'          => 'https://schema.org/NewCondition',
            'used'         => 'https://schema.org/UsedCondition',
            'refurbished'  => 'https://schema.org/RefurbishedCondition',
            'damaged'      => 'https://schema.org/DamagedCondition',
        ];

        return $map[$this->getProductCondition($storeId)] ?? 'https://schema.org/NewCondition';
    }

    /**
     * Default "priceValidUntil" date (Y-m-d) from config, or empty string when unset.
     */
    public function getPriceValidUntilDefault(?int $storeId = null): string
    {
        return trim((string) ($this->value(self::XML_SD_PRICE_VALID_UNTIL_DEFAULT, $storeId) ?? ''));
    }

    // --- Structured Data: ProductGroup + Pros/Cons ---

    public function isProductGroupEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SD_PRODUCT_GROUP_ENABLED, $storeId);
    }

    public function isProsConsEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SD_PROS_CONS_ENABLED, $storeId);
    }

    public function getProsAttribute(?int $storeId = null): string
    {
        $value = (string) ($this->value(self::XML_SD_PROS_ATTRIBUTE, $storeId) ?? '');
        return $value !== '' ? $value : 'product_pros';
    }

    public function getConsAttribute(?int $storeId = null): string
    {
        $value = (string) ($this->value(self::XML_SD_CONS_ATTRIBUTE, $storeId) ?? '');
        return $value !== '' ? $value : 'product_cons';
    }

    /**
     * Stock quantity threshold below which availability becomes LimitedAvailability.
     */
    public function getLimitedStockThreshold(?int $storeId = null): int
    {
        $value = $this->value(self::XML_SD_LIMITED_STOCK_THRESHOLD, $storeId);
        return $value !== null ? max(1, (int) $value) : 5;
    }

    // --- Social Profiles ---

    public const XML_SOCIAL_PROFILE_FACEBOOK  = 'panth_seo/social_profiles/facebook_url';
    public const XML_SOCIAL_PROFILE_TWITTER   = 'panth_seo/social_profiles/twitter_url';
    public const XML_SOCIAL_PROFILE_INSTAGRAM = 'panth_seo/social_profiles/instagram_url';
    public const XML_SOCIAL_PROFILE_LINKEDIN  = 'panth_seo/social_profiles/linkedin_url';
    public const XML_SOCIAL_PROFILE_YOUTUBE   = 'panth_seo/social_profiles/youtube_url';
    public const XML_SOCIAL_PROFILE_PINTEREST = 'panth_seo/social_profiles/pinterest_url';
    public const XML_SOCIAL_PROFILE_TIKTOK    = 'panth_seo/social_profiles/tiktok_url';

    // --- URL Key Automation ---

    public function isAutoUrlKeyEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_URL_AUTO_URL_KEY_ENABLED, $storeId);
    }

    public function getUrlKeyTemplate(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_URL_URL_KEY_TEMPLATE, $storeId) ?? '{{name}}');
    }

    public function isAutoUrlKeyForExisting(?int $storeId = null): bool
    {
        return $this->flag(self::XML_URL_AUTO_URL_KEY_FOR_EXISTING, $storeId);
    }

    // --- Reports & Diagnostics ---

    public function isCrawlAuditEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_REPORTS_CRAWL_AUDIT_ENABLED, $storeId);
    }

    public function getCrawlDepth(?int $storeId = null): int
    {
        return (int) ($this->value(self::XML_REPORTS_CRAWL_DEPTH, $storeId) ?? 100);
    }

    public function isSeoToolbarEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_REPORTS_TOOLBAR_ENABLED, $storeId);
    }

    public function getSeoToolbarAllowedIps(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_REPORTS_TOOLBAR_ALLOWED_IPS, $storeId) ?? '');
    }

    // --- Breadcrumbs ---

    public function getBreadcrumbFormat(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_BREADCRUMBS_FORMAT, $storeId) ?? 'longest');
    }

    public function isBreadcrumbPriorityEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_BREADCRUMBS_PRIORITY_ENABLED, $storeId);
    }

    // --- Image SEO ---

    public function isImageSeoEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_IMAGE_SEO_ENABLED, $storeId);
    }

    public function getImageAltTemplate(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_IMAGE_ALT_TEMPLATE, $storeId) ?? '{{name}} - {{store}}');
    }

    public function getImageTitleTemplate(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_IMAGE_TITLE_TEMPLATE, $storeId) ?? '{{name}}');
    }

    public function isImageGallerySeoEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_IMAGE_GALLERY_ENABLED, $storeId);
    }

    // --- Social Profiles ---

    /**
     * Return a flat list of non-empty, validated social profile URLs.
     *
     * Only http(s) URLs are accepted. Dangerous schemes (javascript:, data:,
     * vbscript:, file:, etc.) are rejected even if an admin pastes them past
     * the client-side validate-url rule.
     *
     * @return array<int, string>
     */
    public function getSocialProfileUrls(?int $storeId = null): array
    {
        $paths = [
            self::XML_SOCIAL_PROFILE_FACEBOOK,
            self::XML_SOCIAL_PROFILE_TWITTER,
            self::XML_SOCIAL_PROFILE_INSTAGRAM,
            self::XML_SOCIAL_PROFILE_LINKEDIN,
            self::XML_SOCIAL_PROFILE_YOUTUBE,
            self::XML_SOCIAL_PROFILE_PINTEREST,
            self::XML_SOCIAL_PROFILE_TIKTOK,
        ];

        $urls = [];
        foreach ($paths as $path) {
            $url = trim((string) ($this->value($path, $storeId) ?? ''));
            if ($url === '' || !$this->isSafeHttpUrl($url)) {
                continue;
            }
            $urls[] = $url;
        }

        return $urls;
    }

    /**
     * Scheme-allowlist URL validator: http or https only, with a valid host.
     * Protects against javascript:, data:, vbscript:, file: etc. regardless
     * of filter_var's evolving semantics.
     */
    private function isSafeHttpUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        return $host !== '';
    }

    // --- IndexNow Protocol ---

    public function isIndexNowEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_INDEXNOW_ENABLED, $storeId);
    }

    public function getIndexNowApiKey(?int $storeId = null): string
    {
        return trim((string) ($this->value(self::XML_INDEXNOW_API_KEY, $storeId) ?? ''));
    }

    // --- Google Merchant Center Feed ---

    public function isMerchantFeedEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_MERCHANT_FEED_ENABLED, $storeId);
    }

    public function isMerchantFeedIncludeOutOfStock(?int $storeId = null): bool
    {
        return $this->flag(self::XML_MERCHANT_FEED_INCLUDE_OOS, $storeId);
    }

    /**
     * Product condition for the Google feed: new, used, or refurbished.
     */
    public function getMerchantFeedDefaultCondition(?int $storeId = null): string
    {
        $value = (string) ($this->value(self::XML_MERCHANT_FEED_DEFAULT_CONDITION, $storeId) ?? 'new');
        $allowed = ['new', 'used', 'refurbished'];
        return in_array($value, $allowed, true) ? $value : 'new';
    }

    /**
     * Attribute code used to map products to Google product categories.
     *
     * Validated to match the Magento EAV attribute code pattern to prevent
     * SQL injection or unexpected behaviour when the value flows into
     * collection/getData calls.
     */
    public function getMerchantFeedGoogleCategoryAttribute(?int $storeId = null): string
    {
        $value = trim((string) ($this->value(self::XML_MERCHANT_FEED_GOOGLE_CAT_ATTRIBUTE, $storeId) ?? ''));
        if ($value === '') {
            return '';
        }
        // Magento attribute codes: lowercase letter, then letters/digits/underscore, max 60 chars
        if (preg_match('/^[a-z][a-z0-9_]{0,59}$/', $value) !== 1) {
            return '';
        }
        return $value;
    }

    /**
     * Two-letter ISO 3166-1 alpha-2 country code for the shipping element.
     *
     * Returns an empty string when the configured value is not a valid
     * two-letter code so that the feed omits the shipping element instead
     * of emitting a malformed value.
     */
    public function getMerchantFeedShippingCountry(?int $storeId = null): string
    {
        $value = strtoupper(trim((string) ($this->value(self::XML_MERCHANT_FEED_SHIPPING_COUNTRY, $storeId) ?? '')));
        if ($value === '') {
            return '';
        }
        if (preg_match('/^[A-Z]{2}$/', $value) !== 1) {
            return '';
        }
        return $value;
    }

    /**
     * Flat shipping price for the feed, normalised to a decimal string.
     *
     * Returns an empty string for non-numeric or negative values so the
     * feed omits the shipping element rather than emitting "0.00 USD".
     */
    public function getMerchantFeedShippingPrice(?int $storeId = null): string
    {
        $raw = trim((string) ($this->value(self::XML_MERCHANT_FEED_SHIPPING_PRICE, $storeId) ?? ''));
        if ($raw === '') {
            return '';
        }
        if (!is_numeric($raw) || (float) $raw < 0) {
            return '';
        }
        return number_format((float) $raw, 2, '.', '');
    }

    // --- Google Analytics 4 ---

    public function isGa4Enabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_GA4_ENABLED, $storeId);
    }

    public function getGa4MeasurementId(?int $storeId = null): string
    {
        return trim((string) ($this->value(self::XML_GA4_MEASUREMENT_ID, $storeId) ?? ''));
    }

    public function isGa4EnhancedEcommerceEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_GA4_ENHANCED_ECOM, $storeId);
    }

    // --- Google Search Console ---

    public function isSearchConsoleIndexingEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SC_INDEXING_API_ENABLED, $storeId);
    }

    public function getSearchConsoleServiceAccountJson(?int $storeId = null): string
    {
        $raw = (string) ($this->value(self::XML_SC_SERVICE_ACCOUNT_JSON, $storeId) ?? '');
        return $raw === '' ? '' : $this->encryptor->decrypt($raw);
    }

    public function getSearchConsoleVerificationCode(?int $storeId = null): string
    {
        return trim((string) ($this->value(self::XML_SC_SITE_VERIFICATION_CODE, $storeId) ?? ''));
    }

    // --- Canonical: Strip Params ---

    public function getCanonicalStripParams(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_CANONICAL_STRIP_PARAMS, $storeId) ?? '');
    }

    // --- Sitemap: Video ---

    public function sitemapIncludeVideo(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_INCLUDE_VIDEO, $storeId);
    }

    // --- Advanced: Last-Modified Header ---

    public function isLastModifiedHeaderEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_ADV_LAST_MODIFIED_HEADER, $storeId);
    }

    // --- Advanced: Speculation Rules ---

    public function isSpeculationRulesEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_ADV_SPECULATION_RULES, $storeId);
    }

    // --- LLMs.txt ---

    public function isLlmsTxtEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_LLMS_TXT_ENABLED, $storeId);
    }

    public function getLlmsTxtSummary(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_LLMS_TXT_SUMMARY, $storeId) ?? '');
    }

    public function getLlmsTxtMaxCategories(?int $storeId = null): int
    {
        return (int) ($this->value(self::XML_LLMS_TXT_MAX_CATEGORIES, $storeId) ?? 20);
    }

    public function getLlmsTxtMaxProducts(?int $storeId = null): int
    {
        return (int) ($this->value(self::XML_LLMS_TXT_MAX_PRODUCTS, $storeId) ?? 50);
    }

    public function getLlmsTxtMaxCms(?int $storeId = null): int
    {
        return (int) ($this->value(self::XML_LLMS_TXT_MAX_CMS, $storeId) ?? 10);
    }

    public function isLlmsTxtGenerateFullEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_LLMS_TXT_GENERATE_FULL, $storeId);
    }

    public function getLlmsTxtShippingPage(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_LLMS_TXT_SHIPPING_PAGE, $storeId) ?? '');
    }

    public function getLlmsTxtReturnsPage(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_LLMS_TXT_RETURNS_PAGE, $storeId) ?? '');
    }

    public function getLlmsTxtAboutPage(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_LLMS_TXT_ABOUT_PAGE, $storeId) ?? '');
    }

    public function getLlmsTxtFaqPage(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_LLMS_TXT_FAQ_PAGE, $storeId) ?? '');
    }

    // --- Organization Details ---

    public function getOrgLegalName(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_ORG_LEGAL_NAME, $storeId) ?? '');
    }

    public function getOrgLogo(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_ORG_LOGO, $storeId) ?? '');
    }

    public function getOrgPhone(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_ORG_PHONE, $storeId) ?? '');
    }

    public function getOrgEmail(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_ORG_EMAIL, $storeId) ?? '');
    }

    public function getOrgStreet(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_ORG_STREET, $storeId) ?? '');
    }

    public function getOrgLocality(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_ORG_LOCALITY, $storeId) ?? '');
    }

    public function getOrgRegion(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_ORG_REGION, $storeId) ?? '');
    }

    public function getOrgPostcode(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_ORG_POSTCODE, $storeId) ?? '');
    }

    public function getOrgCountry(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_ORG_COUNTRY, $storeId) ?? '');
    }

    public function getOrgSameAs(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_ORG_SAME_AS, $storeId) ?? '');
    }

    // --- Structured Data: Business Type, Brand, Return Policy ---

    public function getBusinessType(?int $storeId = null): string
    {
        $value = (string) ($this->value(self::XML_SD_BUSINESS_TYPE, $storeId) ?? 'Organization');
        $allowed = ['Organization', 'LocalBusiness', 'Store', 'OnlineStore'];
        return in_array($value, $allowed, true) ? $value : 'Organization';
    }

    public function getDefaultBrand(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SD_DEFAULT_BRAND, $storeId) ?? '');
    }

    public function getReturnPolicyType(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SD_RETURN_POLICY_TYPE, $storeId) ?? 'refund');
    }

    public function getReturnPolicyFees(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_SD_RETURN_POLICY_FEES, $storeId) ?? 'free');
    }

    // --- Structured Data: Energy Label ---

    public function isEnergyLabelEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SD_ENERGY_LABEL_ENABLED, $storeId);
    }

    public function getEnergyClassAttribute(?int $storeId = null): string
    {
        $value = (string) ($this->value(self::XML_SD_ENERGY_CLASS_ATTRIBUTE, $storeId) ?? '');
        return $value !== '' ? $value : 'energy_class';
    }

    // --- Structured Data: Certification ---

    public function isCertificationEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SD_CERTIFICATION_ENABLED, $storeId);
    }

    public function getCertificationAttribute(?int $storeId = null): string
    {
        $value = (string) ($this->value(self::XML_SD_CERTIFICATION_ATTRIBUTE, $storeId) ?? '');
        return $value !== '' ? $value : 'certifications';
    }

    // --- Structured Data: Sale Event ---

    public function isSaleEventEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SD_SALE_EVENT_ENABLED, $storeId);
    }

    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function value(string $path, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
