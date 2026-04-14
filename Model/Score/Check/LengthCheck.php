<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Score\Check;

use Panth\AdvancedSEO\Model\Score\CheckInterface;

/**
 * Length check: title pixel width (approx) and description char length.
 *
 * Target ranges (SERP):
 *   title pixel width       : 200..580px   (optimum 380..560)
 *   description characters  : 120..160     (optimum 140..156)
 */
class LengthCheck implements CheckInterface
{
    // Per-character pixel width approximations (Arial 16px).
    private const AVG_CHAR_PX = 7.2;
    private const WIDE_CHARS = 'mwMW';
    private const NARROW_CHARS = 'ilItjf.,:;\'';

    public function getCode(): string
    {
        return 'length';
    }

    /**
     * @param array<string,mixed> $context
     * @return array{score:float, max:float, message:string, details?:array<string,mixed>}
     */
    public function run(array $context): array
    {
        $title = (string)($context['meta']['title'] ?? '');
        $description = (string)($context['meta']['description'] ?? '');

        $titlePx = $this->approxPixelWidth($title);
        $descLen = mb_strlen($description);

        $titleScore = $this->rangeScore($titlePx, 380.0, 560.0, 200.0, 600.0);
        $descScore = $this->rangeScore((float)$descLen, 140.0, 156.0, 100.0, 170.0);

        $score = ($titleScore + $descScore) / 2.0;

        $msg = sprintf(
            'Title ~%dpx (%d chars), description %d chars',
            (int)round($titlePx),
            mb_strlen($title),
            $descLen
        );

        return [
            'score' => $score,
            'max' => 100.0,
            'message' => $msg,
            'details' => [
                'title_pixels' => $titlePx,
                'title_chars' => mb_strlen($title),
                'description_chars' => $descLen,
                'title_score' => $titleScore,
                'description_score' => $descScore,
            ],
        ];
    }

    private function approxPixelWidth(string $text): float
    {
        if ($text === '') {
            return 0.0;
        }
        $width = 0.0;
        $len = mb_strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($text, $i, 1);
            if (str_contains(self::WIDE_CHARS, $ch)) {
                $width += self::AVG_CHAR_PX * 1.6;
            } elseif (str_contains(self::NARROW_CHARS, $ch)) {
                $width += self::AVG_CHAR_PX * 0.55;
            } else {
                $width += self::AVG_CHAR_PX;
            }
        }
        return $width;
    }

    /**
     * Piecewise scoring: 100 inside [optMin..optMax], linearly decaying
     * to 0 at hardMin / hardMax, 0 outside.
     */
    private function rangeScore(float $value, float $optMin, float $optMax, float $hardMin, float $hardMax): float
    {
        if ($value >= $optMin && $value <= $optMax) {
            return 100.0;
        }
        if ($value < $hardMin || $value > $hardMax) {
            return 0.0;
        }
        if ($value < $optMin) {
            return max(0.0, (($value - $hardMin) / ($optMin - $hardMin)) * 100.0);
        }
        return max(0.0, (($hardMax - $value) / ($hardMax - $optMax)) * 100.0);
    }
}
