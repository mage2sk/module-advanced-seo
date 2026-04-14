<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

/**
 * Post-crawl analysis: inspects an array of CrawlResult objects and enriches
 * each with categorised SEO issues (missing tags, duplicates, errors, etc.).
 */
class IssueDetector
{
    private const TITLE_MAX_LENGTH       = 60;
    private const DESCRIPTION_MAX_LENGTH = 160;
    private const MAX_REDIRECT_HOPS      = 2;

    /**
     * Analyse crawl results and return enriched copies with detected issues.
     *
     * @param CrawlResult[] $results
     * @return array{results: CrawlResult[], summary: array<string, int>}
     */
    public function analyse(array $results): array
    {
        $titleIndex = $this->buildTitleIndex($results);

        $enriched = [];
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
        ];

        foreach ($results as $result) {
            $issues = $result->issues; // preserve any pre-existing issues

            // --- Status code issues ---
            if ($result->statusCode === 0) {
                $issues[] = 'Fetch failed (no response)';
                $summary['fetch_errors']++;
            } elseif ($result->statusCode === 404) {
                $issues[] = '404 status';
                $summary['status_404']++;
            } elseif ($result->statusCode >= 500 && $result->statusCode < 600) {
                $issues[] = sprintf('%d server error', $result->statusCode);
                $summary['status_5xx']++;
            }

            // Skip further content checks for non-200 pages
            if ($result->statusCode !== 200) {
                $enriched[] = $result->withIssues(array_diff($issues, $result->issues));
                continue;
            }

            // --- Title issues ---
            if ($result->title === '') {
                $issues[] = 'Missing title';
                $summary['missing_title']++;
            } else {
                if (mb_strlen($result->title) > self::TITLE_MAX_LENGTH) {
                    $issues[] = sprintf(
                        'Title too long (%d chars, max %d)',
                        mb_strlen($result->title),
                        self::TITLE_MAX_LENGTH
                    );
                    $summary['title_too_long']++;
                }

                // Duplicate title detection
                $duplicateUrls = $this->findDuplicateTitles($result, $titleIndex);
                if ($duplicateUrls !== []) {
                    foreach ($duplicateUrls as $dupUrl) {
                        $issues[] = 'Duplicate title with URL ' . $dupUrl;
                    }
                    $summary['duplicate_titles']++;
                }
            }

            // --- Description issues ---
            if ($result->description === '') {
                $issues[] = 'Missing description';
                $summary['missing_description']++;
            } elseif (mb_strlen($result->description) > self::DESCRIPTION_MAX_LENGTH) {
                $issues[] = sprintf(
                    'Description too long (%d chars, max %d)',
                    mb_strlen($result->description),
                    self::DESCRIPTION_MAX_LENGTH
                );
                $summary['description_too_long']++;
            }

            // --- Canonical issues ---
            if ($result->canonical === '') {
                $issues[] = 'No canonical';
                $summary['missing_canonical']++;
            }

            $enriched[] = $result->withIssues(array_diff($issues, $result->issues));
        }

        return [
            'results' => $enriched,
            'summary' => $summary,
        ];
    }

    /**
     * Detect redirect chains exceeding the allowed hop count.
     *
     * This requires the crawler to track redirect history. Each entry
     * in $redirectMap should be url => array of intermediate URLs.
     *
     * @param array<string, string[]> $redirectMap URL => list of redirect hops
     * @return array<string, string>  URL => issue description
     */
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

    /**
     * Build an index of normalized title => [url, ...] for duplicate detection.
     *
     * @param CrawlResult[] $results
     * @return array<string, string[]>
     */
    private function buildTitleIndex(array $results): array
    {
        $index = [];
        foreach ($results as $result) {
            if ($result->title === '' || $result->statusCode !== 200) {
                continue;
            }
            $key = mb_strtolower(trim($result->title));
            $index[$key][] = $result->url;
        }
        return $index;
    }

    /**
     * Find other URLs with the same title.
     *
     * @param array<string, string[]> $titleIndex
     * @return string[] URLs that share the same title (excluding self)
     */
    private function findDuplicateTitles(CrawlResult $result, array $titleIndex): array
    {
        $key  = mb_strtolower(trim($result->title));
        $urls = $titleIndex[$key] ?? [];

        if (count($urls) <= 1) {
            return [];
        }

        return array_values(array_filter($urls, fn(string $u): bool => $u !== $result->url));
    }
}
