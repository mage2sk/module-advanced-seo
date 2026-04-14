<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Head;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Outputs `<meta name="google-site-verification" content="XXX">` in <head>.
 *
 * The verification code is read from system config. If empty, nothing is rendered.
 */
class SearchConsoleVerification extends Template
{
    public const XML_VERIFICATION_CODE = 'panth_seo/search_console/site_verification_code';

    public function __construct(
        Context $context,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SeoConfig $seoConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Whether the module is enabled (master switch).
     */
    public function isEnabled(): bool
    {
        return $this->seoConfig->isEnabled();
    }

    /**
     * Return the Google Search Console site verification code from config.
     */
    public function getVerificationCode(): string
    {
        return trim((string) ($this->scopeConfig->getValue(
            self::XML_VERIFICATION_CODE,
            ScopeInterface::SCOPE_STORE
        ) ?? ''));
    }

    /**
     * Whether the verification meta tag should be rendered.
     */
    public function hasVerificationCode(): bool
    {
        return $this->isEnabled() && $this->getVerificationCode() !== '';
    }
}
