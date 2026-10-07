<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api\Data;

/**
 * SEO score row (panth_seo_score).
 */
interface SeoScoreInterface
{
    public const SCORE_ID     = 'score_id';
    public const STORE_ID     = 'store_id';
    public const ENTITY_TYPE  = 'entity_type';
    public const ENTITY_ID    = 'entity_id';
    public const SCORE        = 'score';
    public const GRADE        = 'grade';
    public const BREAKDOWN    = 'breakdown';
    public const ISSUES       = 'issues';
    public const COMPUTED_AT  = 'computed_at';

    public function getScoreId(): ?int;

    public function getStoreId(): int;

    public function setStoreId(int $id): self;

    public function getEntityType(): string;

    public function setEntityType(string $type): self;

    public function getEntityId(): int;

    public function setEntityId(int $id): self;

    public function getScore(): int;

    public function setScore(int $score): self;

    public function getGrade(): string;

    public function setGrade(string $grade): self;

    /** @return array<string,mixed> */
    public function getBreakdown(): array;

    /** @param array<string,mixed> $breakdown */
    public function setBreakdown(array $breakdown): self;

    /** @return array<int,array<string,mixed>> */
    public function getIssues(): array;

    /** @param array<int,array<string,mixed>> $issues */
    public function setIssues(array $issues): self;

    public function getComputedAt(): ?string;
}
