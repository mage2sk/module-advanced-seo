<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

use Magento\Framework\Module\PackageInfo;

class AuditUserAgent
{
    public const PRODUCT = 'PanthSeoAudit';

    private ?string $version = null;

    public function __construct(private readonly ?PackageInfo $packageInfo = null)
    {
    }

    public function getVersion(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }
        $version = '';
        try {
            $version = (string) $this->packageInfo?->getVersion('Panth_AdvancedSEO');
        } catch (\Throwable) {
            $version = '';
        }

        return $this->version = $version !== '' ? $version : 'dev';
    }

    public function forStore(string $baseUrl): string
    {
        return sprintf('%s/%s (+%s)', self::PRODUCT, $this->getVersion(), rtrim($baseUrl, '/') . '/');
    }
}
