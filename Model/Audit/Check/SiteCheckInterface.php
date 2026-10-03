<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\Issue;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

interface SiteCheckInterface
{
    /**
     * @param ParsedPage[] $pages
     * @return Issue[]
     */
    public function checkSite(array $pages, AuditContext $ctx): array;
}
