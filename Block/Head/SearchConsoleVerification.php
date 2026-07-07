<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Head;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

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

    public function isEnabled(): bool
    {
        return $this->seoConfig->isEnabled();
    }

    public function getVerificationCode(): string
    {
        return trim((string) ($this->scopeConfig->getValue(
            self::XML_VERIFICATION_CODE,
            ScopeInterface::SCOPE_STORE
        ) ?? ''));
    }

    public function hasVerificationCode(): bool
    {
        return $this->isEnabled() && $this->getVerificationCode() !== '';
    }
}
