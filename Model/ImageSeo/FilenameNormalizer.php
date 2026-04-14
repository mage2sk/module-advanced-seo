<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\ImageSeo;

/**
 * Slugifies image filenames: transliterates to ASCII, lowercases,
 * replaces any non-alphanumeric with dash, collapses dashes.
 */
class FilenameNormalizer
{
    public function normalize(string $filename): string
    {
        $ext  = '';
        $base = $filename;
        $dot  = strrpos($filename, '.');
        if ($dot !== false) {
            $ext  = strtolower(substr($filename, $dot));
            $base = substr($filename, 0, $dot);
        }

        $base = $this->transliterate($base);
        $base = strtolower($base);
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? $base;
        $base = trim((string) $base, '-');
        if ($base === '') {
            $base = 'image';
        }
        if (strlen($base) > 120) {
            $base = substr($base, 0, 120);
            $base = rtrim($base, '-');
        }
        return $base . $ext;
    }

    private function transliterate(string $text): string
    {
        if (function_exists('transliterator_transliterate')) {
            try {
                $result = \transliterator_transliterate(
                    'Any-Latin; Latin-ASCII; [:Nonspacing Mark:] Remove; NFC',
                    $text
                );
                if (is_string($result) && $result !== '') {
                    return $result;
                }
            } catch (\Throwable) {
                // Fall through to iconv
            }
        }
        if (function_exists('iconv')) {
            try {
                $result = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
                if (is_string($result) && $result !== '') {
                    return $result;
                }
            } catch (\Throwable) {
                // Fall through to raw text
            }
        }
        return $text;
    }
}
