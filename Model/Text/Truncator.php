<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Text;

class Truncator
{
    private const WORD_BOUNDARY_RATIO = 0.6;
    private const TRAILING_CHARS = " \t.,;:-|&";
    private const ELLIPSIS = '...';

    public function truncate(string $value, int $max): string
    {
        if ($value === '' || $max <= 3) {
            return $value;
        }

        $budget = $max - 3;

        if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $max) {
            $cut = mb_substr($value, 0, $budget, 'UTF-8');
            $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
            if ($space !== false && $space >= $this->minimumKeptLength($budget)) {
                $cut = mb_substr($cut, 0, $space, 'UTF-8');
            }

            return rtrim($cut, self::TRAILING_CHARS) . self::ELLIPSIS;
        }

        if (!function_exists('mb_strlen') && strlen($value) > $max) {
            $cut = substr($value, 0, $budget);
            $space = strrpos($cut, ' ');
            if ($space !== false && $space >= $this->minimumKeptLength($budget)) {
                $cut = substr($cut, 0, $space);
            }

            return rtrim($cut, self::TRAILING_CHARS) . self::ELLIPSIS;
        }

        return $value;
    }

    private function minimumKeptLength(int $budget): int
    {
        return (int) floor($budget * self::WORD_BOUNDARY_RATIO);
    }
}
