<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

/**
 * Immutable DTO representing a single crawled page and its detected SEO issues.
 */
class CrawlResult
{
    /**
     * @param string   $url        Absolute URL that was crawled
     * @param int      $statusCode HTTP status code returned
     * @param string   $title      Content of <title> tag (empty if missing)
     * @param string   $description Content of <meta name="description"> (empty if missing)
     * @param string   $canonical  Value of <link rel="canonical"> href (empty if missing)
     * @param string   $robots     Value of <meta name="robots"> content (empty if missing)
     * @param string[] $issues     Human-readable issue strings
     */
    public function __construct(
        public readonly string $url,
        public readonly int $statusCode,
        public readonly string $title = '',
        public readonly string $description = '',
        public readonly string $canonical = '',
        public readonly string $robots = '',
        public readonly array $issues = []
    ) {
    }

    /**
     * Return a copy with additional issues appended.
     *
     * @param string[] $extra
     */
    public function withIssues(array $extra): self
    {
        return new self(
            $this->url,
            $this->statusCode,
            $this->title,
            $this->description,
            $this->canonical,
            $this->robots,
            array_merge($this->issues, $extra)
        );
    }

    /**
     * Serialize to an array suitable for DB persistence.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url'              => $this->url,
            'status_code'      => $this->statusCode,
            'meta_title'       => $this->title,
            'meta_description' => $this->description,
            'canonical'        => $this->canonical,
            'robots'           => $this->robots,
            'issues_json'      => json_encode($this->issues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }
}
