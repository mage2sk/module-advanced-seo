<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Cache;

use Magento\Framework\App\Cache\TypeListInterface;
use Panth\AdvancedSEO\Model\Meta\Cache as MetaCache;
use Psr\Log\LoggerInterface;

class CleanMetaCachePlugin
{
    public const TYPES = ['full_page', 'collections'];

    public function __construct(
        private readonly MetaCache $metaCache,
        private readonly LoggerInterface $logger
    ) {
    }

    public function afterCleanType(TypeListInterface $subject, $result, $typeCode)
    {
        if (in_array((string) $typeCode, self::TYPES, true)) {
            try {
                $this->metaCache->invalidateAll();
            } catch (\Throwable $e) {
                $this->logger->warning('Panth SEO: resolved meta cache clean failed: ' . $e->getMessage());
            }
        }

        return $result;
    }
}
