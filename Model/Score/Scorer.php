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

class Scorer implements SeoScorerInterface
{
    private const ISSUE_THRESHOLD = 60.0;

    private array $checks;

    private array $weights;

    public function __construct(
        private readonly ContextBuilder $contextBuilder,
        private readonly ScoreResource $scoreResource,
        private readonly ResourceConnection $resource,
        private readonly SerializerInterface $serializer,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly SeoScoreFactory $seoScoreFactory,
        private readonly GradeCalculator $gradeCalculator,
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
        $issues = [];
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

            if ($normalized < self::ISSUE_THRESHOLD) {
                $issues[] = [
                    'check' => $code,
                    'score' => $normalized,
                    'message' => (string)($result['message'] ?? ''),
                ];
            }
        }

        $overall = $weightTotal > 0 ? (int)round($weightedSum / $weightTotal) : 0;
        $grade = $this->grade($overall);

        $this->persist($entityType, $entityId, $storeId, $overall, $grade, $breakdown);

        $dto = $this->seoScoreFactory->create();
        $dto->setEntityType($entityType)
            ->setEntityId($entityId)
            ->setStoreId($storeId)
            ->setScore($overall)
            ->setGrade($grade)
            ->setBreakdown($breakdown)
            ->setIssues($issues);
        return $dto;
    }

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
        return $this->gradeCalculator->forScore($score);
    }

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
            SeoScoreInterface::ENTITY_TYPE => $entityType,
            SeoScoreInterface::ENTITY_ID => $entityId,
            SeoScoreInterface::STORE_ID => $storeId,
            SeoScoreInterface::SCORE => $score,
            SeoScoreInterface::GRADE => $grade,
            SeoScoreInterface::BREAKDOWN => $this->serializer->serialize($breakdown),
            SeoScoreInterface::COMPUTED_AT => $this->dateTime->gmtDate(),
        ];
        try {
            $connection->insertOnDuplicate($table, $data, [
                SeoScoreInterface::SCORE,
                SeoScoreInterface::GRADE,
                SeoScoreInterface::BREAKDOWN,
                SeoScoreInterface::COMPUTED_AT,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO score persist failed: ' . $e->getMessage());
        }
    }
}
