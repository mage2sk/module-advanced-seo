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
        try {
            if (!$this->seoConfig->isCanonicalEnabled()) {
                return $result;
            }

            return !$this->seoConfig->isEnabled();
        } catch (\Throwable) {
            return $result;
        }
    }
}
