<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Score;

use Panth\AdvancedSEO\Api\SeoScorerInterface;

class GradeCalculator
{
    private const COLORS = [
        SeoScorerInterface::GRADE_A => '#2e7d32',
        SeoScorerInterface::GRADE_B => '#1565c0',
        SeoScorerInterface::GRADE_C => '#f9a825',
        SeoScorerInterface::GRADE_D => '#ef6c00',
        SeoScorerInterface::GRADE_F => '#c62828',
    ];

    public function forScore(int $score): string
    {
        $score = max(0, min(100, $score));

        return match (true) {
            $score >= 90 => SeoScorerInterface::GRADE_A,
            $score >= 80 => SeoScorerInterface::GRADE_B,
            $score >= 70 => SeoScorerInterface::GRADE_C,
            $score >= 60 => SeoScorerInterface::GRADE_D,
            default      => SeoScorerInterface::GRADE_F,
        };
    }

    public function colorForScore(int $score): string
    {
        return self::COLORS[$this->forScore($score)];
    }

    public function isValid(string $grade): bool
    {
        return isset(self::COLORS[strtoupper(trim($grade))]);
    }

    public function all(): array
    {
        return array_keys(self::COLORS);
    }
}
