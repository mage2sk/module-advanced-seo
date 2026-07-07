<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

class DuplicateScan
{
    private const SAMPLE_LIMIT = 10;

    private const FIELDS = ['meta_title', 'meta_description'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SerializerInterface $serializer,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $connection = $this->resource->getConnection();
        $resolvedTable = $this->resource->getTableName('panth_seo_resolved');
        $dupTable = $this->resource->getTableName('panth_seo_duplicate');

        if (!$connection->isTableExists($resolvedTable) || !$connection->isTableExists($dupTable)) {
            return;
        }

        try {
            $connection->delete($dupTable);

            $now = $this->dateTime->gmtDate();

            foreach (self::FIELDS as $field) {
                $this->scanField($connection, $resolvedTable, $dupTable, $field, $now);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO duplicate scan failed: ' . $e->getMessage());
        }
    }

    private function scanField(
        \Magento\Framework\DB\Adapter\AdapterInterface $connection,
        string $resolvedTable,
        string $dupTable,
        string $field,
        string $now
    ): void {
        $select = $connection->select()
            ->from(
                $resolvedTable,
                [
                    'store_id',
                    'content_hash' => new \Zend_Db_Expr('SHA2(' . $connection->quoteIdentifier($field) . ', 256)'),
                    'dup_count'    => new \Zend_Db_Expr('COUNT(*)'),
                ]
            )
            ->where($connection->quoteIdentifier($field) . ' IS NOT NULL')
            ->where($connection->quoteIdentifier($field) . ' != ?', '')
            ->group(['store_id', new \Zend_Db_Expr('SHA2(' . $connection->quoteIdentifier($field) . ', 256)')])
            ->having('COUNT(*) > 1')
            ->order('dup_count DESC')
            ->limit(500);

        $duplicates = $connection->fetchAll($select);

        foreach ($duplicates as $dup) {
            $storeId = (int) $dup['store_id'];
            $hash    = (string) $dup['content_hash'];
            $count   = (int) $dup['dup_count'];

            $sampleSelect = $connection->select()
                ->from($resolvedTable, ['entity_type', 'entity_id'])
                ->where('store_id = ?', $storeId)
                ->where('SHA2(' . $connection->quoteIdentifier($field) . ', 256) = ?', $hash)
                ->limit(self::SAMPLE_LIMIT);

            $sampleRows = $connection->fetchAll($sampleSelect);
            $sampleJson = $this->serializer->serialize($sampleRows);

            $connection->insertOnDuplicate($dupTable, [
                'store_id'        => $storeId,
                'field'           => $field,
                'hash'            => $hash,
                'count'           => $count,
                'sample_entities' => $sampleJson,
                'detected_at'     => $now,
            ], ['count', 'sample_entities', 'detected_at']);
        }
    }
}
