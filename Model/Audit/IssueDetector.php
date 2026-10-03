<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

class IssueDetector
{
    private const TITLE_MAX_LENGTH       = 60;
    private const DESCRIPTION_MAX_LENGTH = 160;
    private const MAX_REDIRECT_HOPS      = 2;

    private readonly IssueCatalog $catalog;

    public function __construct(?IssueCatalog $catalog = null)
    {
        $this->catalog = $catalog ?? new IssueCatalog();
    }

    public function analyse(array $results, array $redirectMap = []): array
    {
        $titleIndex = $this->buildTitleIndex($results);
        $chainIssues = $this->detectRedirectChains($redirectMap);

        $enriched   = [];
        $structured = [];
        $summary  = [
            'missing_title'       => 0,
            'missing_description' => 0,
            'title_too_long'      => 0,
            'description_too_long' => 0,
            'missing_canonical'   => 0,
            'status_404'          => 0,
            'status_5xx'          => 0,
            'duplicate_titles'    => 0,
            'fetch_errors'        => 0,
            'redirects'           => 0,
            'redirect_chains'     => 0,
            'noindex_pages'       => 0,
        ];

        foreach ($results as $result) {
            $issues = $result->issues;

            if ($result->statusCode === 0) {
                $issues[] = 'Fetch failed (no response)';
                $summary['fetch_errors']++;
                $structured[] = $this->catalog->create('fetch_errors', $result->url, 'HTTP', 'Fetch failed (no response)');
            } elseif ($result->statusCode === 404) {
                $issues[] = '404 status';
                $summary['status_404']++;
                $structured[] = $this->catalog->create('status_404', $result->url, 'HTTP 404', '404 status');
            } elseif ($result->statusCode >= 500 && $result->statusCode < 600) {
                $issues[] = sprintf('%d server error', $result->statusCode);
                $summary['status_5xx']++;
                $structured[] = $this->catalog->create(
                    'status_5xx',
                    $result->url,
                    'HTTP ' . $result->statusCode,
                    sprintf('%d server error', $result->statusCode)
                );
            }

            if ($result->statusCode >= 300 && $result->statusCode < 400) {
                $hops = $redirectMap[$result->url] ?? [];
                $detail = $hops !== []
                    ? sprintf('Redirects (%d) to %s', $result->statusCode, (string) end($hops))
                    : sprintf('Redirects (%d)', $result->statusCode);
                $issues[] = $detail;
                $summary['redirects']++;
                $structured[] = $this->catalog->create('redirects', $result->url, 'HTTP ' . $result->statusCode, $detail);

                if (isset($chainIssues[$result->url])) {
                    $issues[] = $chainIssues[$result->url];
                    $summary['redirect_chains']++;
                    $structured[] = $this->catalog->create(
                        'redirect_chains',
                        $result->url,
                        implode(' > ', array_map('strval', $redirectMap[$result->url] ?? [])),
                        $chainIssues[$result->url]
                    );
                }
            }

            if ($result->statusCode !== 200) {
                $enriched[] = $result->withIssues(array_diff($issues, $result->issues));
                continue;
            }

            if ($this->isNoindex($result->robots)) {
                $summary['noindex_pages']++;
                $enriched[] = $result->withIssues(
                    array_diff(array_merge($issues, ['Noindex page - content checks skipped']), $result->issues)
                );
                continue;
            }

            if ($result->title === '') {
                $issues[] = 'Missing title';
                $summary['missing_title']++;
                $structured[] = $this->catalog->create('missing_title', $result->url, '<title>', 'Missing title');
            } else {
                if (mb_strlen($result->title) > self::TITLE_MAX_LENGTH) {
                    $detail = sprintf(
                        'Title too long (%d chars, max %d)',
                        mb_strlen($result->title),
                        self::TITLE_MAX_LENGTH
                    );
                    $issues[] = $detail;
                    $summary['title_too_long']++;
                    $structured[] = $this->catalog->create('title_too_long', $result->url, $result->title, $detail);
                }

                $duplicateUrls = $this->findDuplicateTitles($result, $titleIndex);
                if ($duplicateUrls !== []) {
                    foreach ($duplicateUrls as $dupUrl) {
                        $issues[] = 'Duplicate title with URL ' . $dupUrl;
                    }
                    $summary['duplicate_titles']++;
                }
            }

            if ($result->description === '') {
                $issues[] = 'Missing description';
                $summary['missing_description']++;
                $structured[] = $this->catalog->create(
                    'missing_description',
                    $result->url,
                    'meta description',
                    'Missing description'
                );
            } elseif (mb_strlen($result->description) > self::DESCRIPTION_MAX_LENGTH) {
                $detail = sprintf(
                    'Description too long (%d chars, max %d)',
                    mb_strlen($result->description),
                    self::DESCRIPTION_MAX_LENGTH
                );
                $issues[] = $detail;
                $summary['description_too_long']++;
                $structured[] = $this->catalog->create('description_too_long', $result->url, 'meta description', $detail);
            }

            if ($result->canonical === '') {
                $issues[] = 'No canonical';
                $summary['missing_canonical']++;
                $structured[] = $this->catalog->create('missing_canonical', $result->url, 'link rel=canonical', 'No canonical');
            }

            $enriched[] = $result->withIssues(array_diff($issues, $result->issues));
        }

        return [
            'results' => $enriched,
            'summary' => $summary,
            'issues'  => $structured,
        ];
    }

    public static function normalizeTitle(string $title): string
    {
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($title)));

        return is_string($normalized) ? $normalized : mb_strtolower(trim($title));
    }

    private function isNoindex(string $robots): bool
    {
        return $robots !== '' && stripos($robots, 'noindex') !== false;
    }

    public function detectRedirectChains(array $redirectMap): array
    {
        $issues = [];
        foreach ($redirectMap as $url => $hops) {
            $hopCount = count($hops);
            if ($hopCount > self::MAX_REDIRECT_HOPS) {
                $issues[$url] = sprintf(
                    'Redirect chain too long (%d hops, max %d)',
                    $hopCount,
                    self::MAX_REDIRECT_HOPS
                );
            }
        }
        return $issues;
    }

    private function buildTitleIndex(array $results): array
    {
        $results = array_filter(
            $results,
            fn ($r): bool => !$this->isNoindex($r->robots)
        );

        $index = [];
        foreach ($results as $result) {
            if ($result->title === '' || $result->statusCode !== 200) {
                continue;
            }
            $key = self::normalizeTitle($result->title);
            $index[$key][] = $result->url;
        }
        return $index;
    }

    private function findDuplicateTitles(CrawlResult $result, array $titleIndex): array
    {
        $key  = self::normalizeTitle($result->title);
        $urls = $titleIndex[$key] ?? [];

        if (count($urls) <= 1) {
            return [];
        }

        return array_values(array_filter($urls, fn(string $u): bool => $u !== $result->url));
    }
}
