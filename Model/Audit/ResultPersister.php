<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

class ResultPersister
{
    private const BATCH_INSERT_SIZE = 100;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    public function persist(int $storeId, array $results): int
    {
        if ($results === []) {
            return 0;
        }

        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('panth_seo_crawl_result');

        if (!$connection->isTableExists($table)) {
            $this->logger->warning('Panth SEO crawl: table ' . $table . ' does not exist, results not saved.');
            return 0;
        }

        $now   = $this->dateTime->gmtDate();
        $saved = 0;

        $connection->beginTransaction();

        try {
            $connection->delete($table, ['store_id = ?' => $storeId]);

            $buffer = [];
            foreach ($results as $result) {
                $row               = $result->toArray();
                $row['store_id']   = $storeId;
                $row['crawled_at'] = $now;

                $buffer[] = $row;

                if (count($buffer) >= self::BATCH_INSERT_SIZE) {
                    $connection->insertMultiple($table, $buffer);
                    $saved += count($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $connection->insertMultiple($table, $buffer);
                $saved += count($buffer);
            }

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->logger->error('Panth SEO crawl: saving results for store ' . $storeId . ' failed: ' . $e->getMessage());
            throw $e;
        }

        return $saved;
    }
}
