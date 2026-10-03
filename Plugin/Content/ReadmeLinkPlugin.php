<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Content;

use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\Fix\ReadmeLinkRewriter;

class ReadmeLinkPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly ReadmeLinkRewriter $rewriter
    ) {
    }

    public function afterProductAttribute($subject, $result)
    {
        return $this->apply($result);
    }

    public function afterCategoryAttribute($subject, $result)
    {
        return $this->apply($result);
    }

    public function afterFilter($subject, $result)
    {
        return $this->apply($result);
    }

    private function apply(mixed $result): mixed
    {
        if (!is_string($result) || $result === '') {
            return $result;
        }
        try {
            if (!$this->config->isAuditFixEnabled(Config::AUDIT_FIX_REWRITE_RELATIVE_README_LINKS)) {
                return $result;
            }

            return $this->rewriter->rewrite($result);
        } catch (\Throwable) {
            return $result;
        }
    }
}
