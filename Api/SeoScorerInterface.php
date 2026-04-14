<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api;

use Panth\AdvancedSEO\Api\Data\SeoScoreInterface;

/**
 * Computes a 0-100 SEO score for a single entity.
 */
interface SeoScorerInterface
{
    public const GRADE_A = 'A';
    public const GRADE_B = 'B';
    public const GRADE_C = 'C';
    public const GRADE_D = 'D';
    public const GRADE_F = 'F';

    public function score(string $entityType, int $entityId, int $storeId): SeoScoreInterface;

    /**
     * @param int[] $entityIds
     * @return SeoScoreInterface[] keyed by entity id
     */
    public function scoreBatch(string $entityType, array $entityIds, int $storeId): array;
}
