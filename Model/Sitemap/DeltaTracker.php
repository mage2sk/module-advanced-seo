<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Sitemap;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\CacheInterface;

/**
 * Tracks per-store last sitemap run timestamp to support delta rebuilds.
 * Stored in the default Magento cache under stable keys.
 */
class DeltaTracker
{
    private const KEY_PREFIX = 'panth_seo_sitemap_last_run_';
    private const TAG = 'panth_seo_sitemap';
    private const TTL = 31536000; // 1 year

    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    public function getLastRun(int $storeId): ?string
    {
        $v = $this->cache->load(self::KEY_PREFIX . $storeId);
        return is_string($v) && $v !== '' ? $v : null;
    }

    public function mark(int $storeId, string $iso8601): void
    {
        $this->cache->save($iso8601, self::KEY_PREFIX . $storeId, [self::TAG], self::TTL);
    }

    public function clear(int $storeId): void
    {
        $this->cache->remove(self::KEY_PREFIX . $storeId);
    }
}
