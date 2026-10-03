<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

class CrawlResult
{
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
