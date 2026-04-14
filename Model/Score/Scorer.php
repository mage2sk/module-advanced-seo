<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Score;

use Panth\AdvancedSEO\Api\Data\SeoScoreInterface;
use Panth\AdvancedSEO\Api\SeoScorerInterface;
use Panth\AdvancedSEO\Model\ResourceModel\Score as ScoreResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Runs all configured score checks and produces a weighted 0..100 score,
 * persisting the result (plus per-check breakdown) to panth_seo_score.
 */
class Scorer implements SeoScorerInterface
{
    /** @var array<string,CheckInterface> */
    private array $checks;

    /** @var array<string,float> */
    private array $weights;

    /**
     * @param array<string,CheckInterface> $checks       code => check instance
     * @param array<string,float>          $weights      code => weight
     */
    public function __construct(
        private readonly ContextBuilder $contextBuilder,
        private readonly ScoreResource $scoreResource,
        private readonly ResourceConnection $resource,
        private readonly SerializerInterface $serializer,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly SeoScoreFactory $seoScoreFactory,
        array $checks = [],
        array $weights = []
    ) {
        $this->checks = $checks;
        $this->weights = $weights;
    }

    public function score(string $entityType, int $entityId, int $storeId): SeoScoreInterface
    {
        $context = $this->contextBuilder->build($entityType, $entityId, $storeId);

        $breakdown = [];
        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($this->checks as $code => $check) {
            try {
                $result = $check->run($context);
            } catch (\Throwable $e) {
                $this->logger->warning('Panth SEO score check failed', [
                    'code' => $code,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $score = (float)($result['score'] ?? 0);
            $max = (float)($result['max'] ?? 100);
            if ($max <= 0) {
                continue;
            }
            $weight = (float)($this->weights[$code] ?? 1.0);
            $normalized = max(0.0, min(100.0, ($score / $max) * 100.0));

            $weightedSum += $normalized * $weight;
            $weightTotal += $weight;

            $breakdown[$code] = [
                'score' => $normalized,
                'weight' => $weight,
                'message' => (string)($result['message'] ?? ''),
                'details' => $result['details'] ?? [],
            ];
        }

        $overall = $weightTotal > 0 ? (int)round($weightedSum / $weightTotal) : 0;
        $grade = $this->grade($overall);

        $this->persist($entityType, $entityId, $storeId, $overall, $grade, $breakdown);

        /** @var SeoScore $dto */
        $dto = $this->seoScoreFactory->create();
        $dto->setEntityType($entityType)
            ->setEntityId($entityId)
            ->setStoreId($storeId)
            ->setScore($overall)
            ->setGrade($grade)
            ->setBreakdown($breakdown)
            ->setIssues([]);
        return $dto;
    }

    /**
     * @inheritDoc
     */
    public function scoreBatch(string $entityType, array $entityIds, int $storeId): array
    {
        $out = [];
        foreach ($entityIds as $id) {
            $id = (int)$id;
            $out[$id] = $this->score($entityType, $id, $storeId);
        }
        return $out;
    }

    private function grade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            $score >= 40 => 'E',
            default => 'F',
        };
    }

    /**
     * @param array<string,array<string,mixed>> $breakdown
     */
    private function persist(
        string $entityType,
        int $entityId,
        int $storeId,
        int $score,
        string $grade,
        array $breakdown
    ): void {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_score');
        $data = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'store_id' => $storeId,
            'score' => $score,
            'grade' => $grade,
            'breakdown' => $this->serializer->serialize($breakdown),
            'updated_at' => $this->dateTime->gmtDate(),
        ];
        try {
            $connection->insertOnDuplicate($table, $data, ['score', 'grade', 'breakdown', 'updated_at']);
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO score persist failed: ' . $e->getMessage());
        }
    }
}
