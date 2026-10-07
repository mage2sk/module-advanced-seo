<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Frontend;

use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\View\Layout;
use Panth\AdvancedSEO\ViewModel\SeoToolbar;

class SeoToolbarCacheablePlugin
{
    public const CONTEXT_KEY = 'panth_seo_toolbar';

    private ?bool $allowed = null;

    public function __construct(
        private readonly SeoToolbar $toolbar,
        private readonly HttpContext $httpContext
    ) {
    }

    public function afterIsCacheable(Layout $subject, $result)
    {
        if (!$result || !$this->isToolbarShown()) {
            return $result;
        }
        $this->httpContext->setValue(self::CONTEXT_KEY, 1, 0);

        return false;
    }

    private function isToolbarShown(): bool
    {
        if ($this->allowed === null) {
            try {
                $this->allowed = $this->toolbar->isAllowed();
            } catch (\Throwable) {
                $this->allowed = false;
            }
        }

        return $this->allowed;
    }
}
