<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Score\Check;

use Panth\AdvancedSEO\Model\Score\CheckInterface;

class ReadabilityCheck implements CheckInterface
{
    private const MIN_WORDS = 30;

    public function getCode(): string
    {
        return 'readability';
    }

    public function run(array $context): array
    {
        $text = trim(strip_tags((string)($context['content'] ?? '')));
        if ($text === '') {
            $text = trim((string)($context['meta']['description'] ?? ''));
        }
        if ($text === '') {
            return ['score' => 0.0, 'max' => 0.0, 'message' => 'No content to analyse - not scored'];
        }

        if (!$this->isLatinText($text)) {
            return [
                'score' => 0.0,
                'max' => 0.0,
                'message' => 'Content is not predominantly Latin script - Flesch Reading Ease does not apply',
            ];
        }

        if ($this->countWords($text) < self::MIN_WORDS) {
            return [
                'score' => 0.0,
                'max' => 0.0,
                'message' => sprintf('Fewer than %d words - too short to score reliably', self::MIN_WORDS),
            ];
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

    private function countWords(string $text): int
    {
        $parts = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? count($parts) : 0;
    }

    private function isLatinText(string $text): bool
    {
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters === false || $letters === 0) {
            return false;
        }

        $latin = preg_match_all('/\p{Latin}/u', $text);
        if ($latin === false) {
            return false;
        }

        return ($latin / $letters) >= 0.6;
    }
}
