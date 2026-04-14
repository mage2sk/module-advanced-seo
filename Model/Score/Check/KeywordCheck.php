<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Score\Check;

use Panth\AdvancedSEO\Model\Score\CheckInterface;

/**
 * Keyword check: verifies that meta keywords appear in title/description/content
 * at reasonable densities (0.5% – 3%).
 */
class KeywordCheck implements CheckInterface
{
    public function getCode(): string
    {
        return 'keyword';
    }

    /**
     * @param array<string,mixed> $context
     * @return array{score:float, max:float, message:string, details?:array<string,mixed>}
     */
    public function run(array $context): array
    {
        $keywords = trim((string)($context['meta']['keywords'] ?? ''));
        if ($keywords === '') {
            return [
                'score' => 30.0,
                'max' => 100.0,
                'message' => 'No meta keywords defined',
            ];
        }

        $title = mb_strtolower((string)($context['meta']['title'] ?? ''));
        $desc = mb_strtolower((string)($context['meta']['description'] ?? ''));
        $content = mb_strtolower(strip_tags((string)($context['content'] ?? '')));

        $wordsInContent = max(1, str_word_count($content));

        $list = array_filter(array_map('trim', explode(',', mb_strtolower($keywords))));
        if ($list === []) {
            return [
                'score' => 30.0,
                'max' => 100.0,
                'message' => 'No meta keywords defined',
            ];
        }

        $totalScore = 0.0;
        $details = [];
        foreach ($list as $kw) {
            if ($kw === '') {
                continue;
            }
            $inTitle = str_contains($title, $kw);
            $inDesc = str_contains($desc, $kw);
            $occurrences = substr_count($content, $kw);
            $density = ($occurrences * max(1, str_word_count($kw))) / $wordsInContent * 100.0;

            $kwScore = 0.0;
            if ($inTitle) {
                $kwScore += 40.0;
            }
            if ($inDesc) {
                $kwScore += 30.0;
            }
            if ($density >= 0.5 && $density <= 3.0) {
                $kwScore += 30.0;
            } elseif ($density > 0 && $density < 0.5) {
                $kwScore += 15.0;
            } elseif ($density > 3.0 && $density <= 5.0) {
                $kwScore += 10.0;
            }

            $totalScore += $kwScore;
            $details[$kw] = [
                'in_title' => $inTitle,
                'in_description' => $inDesc,
                'density' => round($density, 2),
                'score' => $kwScore,
            ];
        }

        $avg = $totalScore / count($list);

        return [
            'score' => $avg,
            'max' => 100.0,
            'message' => sprintf('%d keyword(s) evaluated', count($list)),
            'details' => $details,
        ];
    }
}
