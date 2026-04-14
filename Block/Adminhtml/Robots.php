<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Robots extends Template
{
    protected $_template = 'Panth_AdvancedSEO::robots.phtml';

    public function __construct(
        Context $context,
        private readonly ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getRobotsContent(): string
    {
        $content = (string)$this->scopeConfig->getValue(
            'design/search_engine_robots/custom_instructions',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        if ($content === '') {
            return "User-agent: *\nDisallow: /checkout/\nDisallow: /customer/\nDisallow: /catalogsearch/\n\nSitemap: "
                . $this->getBaseUrl() . "sitemap.xml\n";
        }
        return $content;
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('panth_seo/robots/save');
    }
}
