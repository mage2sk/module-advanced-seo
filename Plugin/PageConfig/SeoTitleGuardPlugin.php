<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\PageConfig;

use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\Fix\SeoTitleHolder;

class SeoTitleGuardPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly SeoTitleHolder $holder,
        private readonly LayoutInterface $layout
    ) {
    }

    public function afterPublicBuild(PageConfig $subject, $result = null)
    {
        try {
            $seoTitle = $this->holder->get();
            if ($seoTitle === null || !$this->config->isAuditFixEnabled(Config::AUDIT_FIX_SEO_TITLE_WINS_OVER_H1)) {
                return $result;
            }
            $current = (string) $subject->getTitle()->getShort();
            if ($current === '' || $current === $seoTitle) {
                return $result;
            }
            $heading = $this->layout->getBlock('page.main.title');
            if (is_object($heading) && method_exists($heading, 'setPageTitle') && method_exists($heading, 'getPageHeading')) {
                $heading->setPageTitle($heading->getPageHeading());
            }
            $subject->getTitle()->set($seoTitle);
        } catch (\Throwable) {
            return $result;
        }

        return $result;
    }
}
