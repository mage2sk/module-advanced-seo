<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Catalog\Canonical;

use Panth\AdvancedSEO\Helper\Config as SeoConfig;

class NativeCanonicalSuppressor
{
    public function __construct(
        private readonly SeoConfig $seoConfig
    ) {
    }

    public function afterCanUseCanonicalTag($subject, $result)
    {
        return $this->shouldSuppress() ? false : $result;
    }

    private function shouldSuppress(): bool
    {
        try {
            return $this->seoConfig->isEnabled() && $this->seoConfig->isCanonicalEnabled();
        } catch (\Throwable) {
            return false;
        }
    }
}
