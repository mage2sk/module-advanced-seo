<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\UrlRewrite;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\UrlRewrite\Model\ResourceModel\UrlRewrite as UrlRewriteResource;
use Magento\UrlRewrite\Model\UrlRewrite as UrlRewriteModel;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Psr\Log\LoggerInterface;

/**
 * Watches UrlRewrite saves for slug changes on products and logs a redirect
 * entry into `panth_seo_redirect` so the redirect module (owned by another
 * agent) can serve it. Also dedupes: if an existing row with same store +
 * pattern exists we simply refresh its target.
 */
class CleanupPlugin
{
    private const REDIRECT_TABLE = 'panth_seo_redirect';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly SeoConfig $seoConfig
    ) {
    }

    public function beforeSave(UrlRewriteResource $subject, \Magento\Framework\Model\AbstractModel $object): array
    {
        try {
            if (!$this->seoConfig->isEnabled()) {
                return [$object];
            }
            if (!$object instanceof UrlRewriteModel || !$object->getId()) {
                return [$object];
            }
            if ((string) $object->getEntityType() !== 'product') {
                return [$object];
            }
            $original = $object->getOrigData('request_path');
            $new      = $object->getRequestPath();
            if ($original === null || $original === '' || $original === $new) {
                return [$object];
            }
            $this->recordRedirect(
                (int) $object->getStoreId(),
                (string) $original,
                (string) $new
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO urlrewrite cleanup failed', ['error' => $e->getMessage()]);
        }
        return [$object];
    }

    private function recordRedirect(int $storeId, string $from, string $to): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::REDIRECT_TABLE);

        $existing = $connection->fetchOne(
            $connection->select()
                ->from($table, ['redirect_id'])
                ->where('store_id = ?', $storeId)
                ->where('pattern = ?', $from)
                ->where('match_type = ?', 'literal')
                ->limit(1)
        );

        $now = $this->dateTime->gmtDate();
        if ($existing) {
            $connection->update(
                $table,
                ['target' => $to, 'is_active' => 1, 'updated_at' => $now],
                ['redirect_id = ?' => (int) $existing]
            );
            return;
        }

        $connection->insert($table, [
            'store_id'    => $storeId,
            'match_type'  => 'literal',
            'pattern'     => $from,
            'target'      => $to,
            'status_code' => 301,
            'priority'    => 10,
            'is_active'   => 1,
            'hit_count'   => 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }
}
