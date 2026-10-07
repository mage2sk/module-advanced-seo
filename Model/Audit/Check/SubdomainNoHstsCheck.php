<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ProbeResult;

class SubdomainNoHstsCheck implements SiteCheckInterface
{
    public const CODE = 'subdomain_no_hsts';

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public function checkSite(array $pages, AuditContext $ctx): array
    {
        $issues = [];
        foreach ($ctx->hostChecks as $host => $probe) {
            if (!$probe instanceof ProbeResult || $probe->status === 0) {
                continue;
            }
            if ($probe->header('strict-transport-security') !== '') {
                continue;
            }
            $issues[] = $this->catalog->create(
                self::CODE,
                'https://' . $host . '/',
                'Strict-Transport-Security',
                sprintf('https://%s/ answered HTTP %d without a Strict-Transport-Security header.', $host, $probe->status)
            );
        }

        return $issues;
    }
}
