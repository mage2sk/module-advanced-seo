<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Queue;

use Panth\AdvancedSEO\Api\MetaGeneratorInterface;
use Panth\AdvancedSEO\Model\Score\ContextBuilder;
use Panth\AdvancedSEO\Model\GenerationJob;
use Panth\AdvancedSEO\Model\ResourceModel\GenerationJob as JobResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Queue consumer for topic `panth_seo.generate_meta`.
 *
 * Message payload: JSON { "job_id": int } — the job row must already exist.
 */
class BulkGenerateConsumer
{
    public function __construct(
        private readonly MetaGeneratorInterface $generator,
        private readonly ContextBuilder $contextBuilder,
        private readonly ResourceConnection $resource,
        private readonly JobResource $jobResource,
        private readonly SerializerInterface $serializer,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $message): void
    {
        $decoded = json_decode($message, true);
        if (!is_array($decoded) || !isset($decoded['job_id'])) {
            $this->logger->warning('Panth SEO generate: invalid message', ['message' => $message]);
            return;
        }
        $jobId = (int)$decoded['job_id'];

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_generation_job');
        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('job_id = ?', $jobId)->limit(1)
        );
        if (!$row) {
            $this->logger->warning('Panth SEO generate: job not found', ['job_id' => $jobId]);
            return;
        }

        $connection->update(
            $table,
            ['status' => GenerationJob::STATUS_PROCESSING, 'updated_at' => $this->dateTime->gmtDate()],
            ['job_id = ?' => $jobId]
        );

        $options = json_decode((string)($row['options'] ?? '{}'), true) ?: [];
        $entityIds = (array)($options['entity_ids'] ?? []);
        $entityType = (string)$row['entity_type'];
        $storeId = (int)$row['store_id'];
        $processed = 0;
        $failed = 0;
        $results = [];

        foreach ($entityIds as $entityId) {
            try {
                $context = $this->contextBuilder->build($entityType, (int)$entityId, $storeId);
                $result = $this->generator->generate($context);

                if ($result['title'] === '' && $result['description'] === '') {
                    $failed++;
                } else {
                    $results[(int)$entityId] = [
                        'draft_title' => (string)$result['title'],
                        'draft_description' => (string)$result['description'],
                        'confidence' => (float)$result['confidence'],
                    ];
                    $processed++;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Panth SEO generate failed for entity', [
                    'job_id' => $jobId,
                    'entity_id' => $entityId,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        $status = ($processed > 0) ? GenerationJob::STATUS_DRAFT : GenerationJob::STATUS_FAILED;
        $options['results'] = $results;

        try {
            $connection->update(
                $table,
                [
                    'status' => $status,
                    'processed' => $processed,
                    'failed' => $failed,
                    'options' => json_encode($options),
                    'updated_at' => $this->dateTime->gmtDate(),
                ],
                ['job_id = ?' => $jobId]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO generate failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            $connection->update(
                $table,
                [
                    'status' => GenerationJob::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                    'updated_at' => $this->dateTime->gmtDate(),
                ],
                ['job_id = ?' => $jobId]
            );
        }
    }
}
