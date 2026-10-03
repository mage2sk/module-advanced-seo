<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\Issue;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\UrlHelper;

class LlmsTxtCheck implements SiteCheckInterface
{
    public const CODE = 'llms_txt';

    public const MAX_BYTES = 512000;

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public static function priorityUrls(string $body): array
    {
        $urls    = [];
        $inside  = false;
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            if (preg_match('/^\s*#+\s*(.+?)\s*$/', $line, $m) === 1) {
                $inside = stripos($m[1], 'priority url') !== false;
                continue;
            }
            if ($inside && preg_match_all('~https?://[^\s)\]>"\']+~i', $line, $found)) {
                foreach ($found[0] as $url) {
                    $urls[] = rtrim($url, '.,;');
                }
            }
        }

        return array_values(array_unique($urls));
    }

    public function checkSite(array $pages, AuditContext $ctx): array
    {
        $probe = $ctx->llmsTxt;
        if ($probe === null) {
            return [];
        }
        $url = rtrim($ctx->baseUrl, '/') . '/llms.txt';
        if ($probe->status === 0 || $probe->status === 404 || $probe->status === 410) {
            return [$this->catalog->create(
                self::CODE,
                $url,
                '/llms.txt',
                'No llms.txt found' . ($probe->status > 0 ? ' (HTTP ' . $probe->status . ').' : '.')
            )];
        }
        if ($probe->status !== 200) {
            return [$this->catalog->create(
                self::CODE,
                $url,
                '/llms.txt',
                sprintf('llms.txt returns HTTP %d.', $probe->status),
                Issue::SEVERITY_WARNING
            )];
        }

        $issues = [];
        $type   = $probe->header('content-type');
        if (stripos($type, 'text/plain') === false) {
            $issues[] = $this->catalog->create(
                self::CODE,
                $url,
                'Content-Type',
                sprintf('llms.txt is served as "%s", not text/plain.', $type !== '' ? $type : 'no content type'),
                Issue::SEVERITY_WARNING
            );
        }
        $size = max(strlen($probe->body), (int) $probe->header('content-length'));
        if ($size > self::MAX_BYTES) {
            $issues[] = $this->catalog->create(
                self::CODE,
                $url,
                'size',
                sprintf('llms.txt is %d KB; keep it under 500 KB.', (int) round($size / 1024)),
                Issue::SEVERITY_WARNING
            );
        }
        $offTopic = array_values(array_filter(
            self::priorityUrls($probe->body),
            static fn (string $candidate): bool => !UrlHelper::sameHost($candidate, $ctx->host())
        ));
        if ($offTopic !== []) {
            $issues[] = $this->catalog->create(
                self::CODE,
                $url,
                'Priority URLs',
                sprintf(
                    'The Priority URLs section lists %d URL(s) outside this store: %s',
                    count($offTopic),
                    implode(', ', array_slice($offTopic, 0, 5))
                ),
                Issue::SEVERITY_WARNING
            );
        }

        return $issues;
    }
}
