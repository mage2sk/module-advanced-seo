<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Score\Check;

use Panth\AdvancedSEO\Model\Score\CheckInterface;

/**
 * Flesch Reading Ease approximation.
 *
 *   FRE = 206.835 - 1.015*(words/sentences) - 84.6*(syllables/words)
 *
 * We score so that FRE in [60..80] gets 100, trailing off outside.
 */
class ReadabilityCheck implements CheckInterface
{
    public function getCode(): string
    {
        return 'readability';
    }

    /**
     * @param array<string,mixed> $context
     * @return array{score:float, max:float, message:string, details?:array<string,mixed>}
     */
    public function run(array $context): array
    {
        $text = trim(strip_tags((string)($context['content'] ?? '')));
        if ($text === '') {
            $text = trim((string)($context['meta']['description'] ?? ''));
        }
        if ($text === '') {
            return ['score' => 0.0, 'max' => 100.0, 'message' => 'No content to analyse'];
        }

        $sentences = max(1, preg_match_all('/[.!?]+/u', $text));
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $wordCount = max(1, count($words));

        $syllables = 0;
        foreach ($words as $w) {
            $syllables += $this->syllables($w);
        }

        $fre = 206.835 - 1.015 * ($wordCount / $sentences) - 84.6 * ($syllables / $wordCount);
        $fre = max(0.0, min(100.0, $fre));

        $score = $this->score($fre);

        return [
            'score' => $score,
            'max' => 100.0,
            'message' => sprintf('Flesch Reading Ease %.1f', $fre),
            'details' => [
                'fre' => $fre,
                'sentences' => $sentences,
                'words' => $wordCount,
                'syllables' => $syllables,
            ],
        ];
    }

    private function syllables(string $word): int
    {
        $word = preg_replace('/[^a-zA-Z]/', '', $word) ?? '';
        if ($word === '') {
            return 0;
        }
        $word = strtolower($word);
        $word = preg_replace('/e$/', '', $word) ?? $word;
        preg_match_all('/[aeiouy]+/', $word, $m);
        return max(1, count($m[0] ?? []));
    }

    private function score(float $fre): float
    {
        if ($fre >= 60.0 && $fre <= 80.0) {
            return 100.0;
        }
        if ($fre >= 50.0 && $fre < 60.0) {
            return 60.0 + ($fre - 50.0) * 4.0;
        }
        if ($fre > 80.0 && $fre <= 90.0) {
            return 100.0 - ($fre - 80.0) * 4.0;
        }
        if ($fre >= 30.0 && $fre < 50.0) {
            return max(0.0, ($fre - 30.0) * 3.0);
        }
        if ($fre > 90.0) {
            return 50.0;
        }
        return 0.0;
    }
}
