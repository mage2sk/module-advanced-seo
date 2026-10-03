<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Fix;

class ReadmeLinkRewriter
{
    private const ANCHOR = '~<a\b([^>]*?)\bhref\s*=\s*(["\'])((?:\./)?docs/[^"\']*)\2([^>]*)>(.*?)</a>~is';

    private const RAW_IMAGE = '~https://raw\.githubusercontent\.com/([^/"\'\s]+)/([^/"\'\s]+)/([^/"\'\s]+)/~i';

    private const RAW_ANCHOR = '~(["\'])https?://(?:www\.)?github\.com/([^/"\']+)/([^/"\']+)/raw/[^/"\'#?]+/?#([^"\']+)\1~i';

    public function rewrite(string $html): string
    {
        if ($html === '' || (stripos($html, 'docs/') === false && stripos($html, '/raw/') === false)) {
            return $html;
        }

        $rewritten = preg_replace_callback(
            self::ANCHOR,
            static function (array $m): string {
                if (preg_match(self::RAW_IMAGE, $m[5], $raw) !== 1) {
                    return $m[0];
                }
                $base = 'https://raw.githubusercontent.com/' . $raw[1] . '/' . $raw[2] . '/' . $raw[3] . '/';
                $path = preg_replace('~^\./~', '', $m[3]);

                return '<a' . $m[1] . 'href=' . $m[2] . $base . $path . $m[2] . $m[4] . '>' . $m[5] . '</a>';
            },
            $html
        );
        $html = is_string($rewritten) ? $rewritten : $html;

        $anchors = preg_replace_callback(
            self::RAW_ANCHOR,
            static fn (array $m): string => $m[1] . 'https://github.com/' . $m[2] . '/' . $m[3] . '#' . $m[4] . $m[1],
            $html
        );

        return is_string($anchors) ? $anchors : $html;
    }
}
