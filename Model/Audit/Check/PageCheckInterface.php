<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\Issue;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

interface PageCheckInterface
{
    /**
     * @return Issue[]
     */
    public function check(ParsedPage $page, AuditContext $ctx): array;
}
