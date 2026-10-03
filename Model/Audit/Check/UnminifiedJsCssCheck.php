<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;
use Panth\AdvancedSEO\Model\Audit\UrlHelper;

class UnminifiedJsCssCheck implements SiteCheckInterface
{
    public const CODE = 'unminified_js_css';

    public const WHITESPACE_RATIO = 0.15;
    public const MIN_AVG_LINE     = 120;
    public const MIN_BYTES        = 512;

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public static function looksUnminified(string $body): bool
    {
        $length = strlen($body);
        if ($length < self::MIN_BYTES) {
            return false;
        }
        $whitespace = preg_match_all('/\s/', $body);
        $lines      = substr_count($body, "\n") + 1;

        return ($whitespace / $length) > self::WHITESPACE_RATIO || ($length / $lines) < self::MIN_AVG_LINE;
    }

    public function checkSite(array $pages, AuditContext $ctx): array
    {
        if ($ctx->minifyJs && $ctx->minifyCss) {
            return [];
        }
        $host      = $ctx->host();
        $resources = [];
        foreach ($pages as $page) {
            if (!$page instanceof ParsedPage || !$page->isOk()) {
                continue;
            }
            foreach (['js' => $page->scripts, 'css' => $page->styles] as $type => $urls) {
                foreach ($urls as $url) {
                    if (!UrlHelper::sameHost($url, $host)) {
                        continue;
                    }
                    $resources[$url]['type']    = $type;
                    $resources[$url]['pages'][] = $page->url;
                }
            }
        }

        $issues = [];
        foreach ($resources as $url => $info) {
            if (($info['type'] === 'js' && $ctx->minifyJs) || ($info['type'] === 'css' && $ctx->minifyCss)) {
                continue;
            }
            $body = $ctx->resourceBodies[$url] ?? null;
            if (!is_string($body) || !self::looksUnminified($body)) {
                continue;
            }
            $pagesUsing = array_values(array_unique($info['pages']));
            $issues[] = $this->catalog->create(
                self::CODE,
                $url,
                $pagesUsing[0],
                sprintf(
                    'Unminified %s referenced by %d page(s); %s is 0.',
                    strtoupper($info['type']),
                    count($pagesUsing),
                    $info['type'] === 'js' ? 'dev/js/minify_files' : 'dev/css/minify_files'
                )
            );
        }

        return $issues;
    }
}
