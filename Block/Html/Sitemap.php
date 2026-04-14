<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Html;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Panth\AdvancedSEO\ViewModel\HtmlSitemap as HtmlSitemapViewModel;

/**
 * HTML sitemap block. Theme-agnostic: the template is plain PHP and
 * contains no JavaScript, Alpine, or mage-init directives.
 */
class Sitemap extends Template
{
    public function __construct(
        Context $context,
        private readonly HtmlSitemapViewModel $htmlSitemap,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getHtmlSitemapViewModel(): HtmlSitemapViewModel
    {
        return $this->htmlSitemap;
    }
}
