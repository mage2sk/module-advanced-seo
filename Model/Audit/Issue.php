<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

class Issue
{
    public const SEVERITY_ERROR   = 'error';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_NOTICE  = 'notice';

    public const CATEGORY_CRAWLABILITY    = 'crawlability';
    public const CATEGORY_CONTENT         = 'content';
    public const CATEGORY_META            = 'meta';
    public const CATEGORY_STRUCTURED_DATA = 'structured_data';
    public const CATEGORY_LINKS           = 'links';
    public const CATEGORY_PERFORMANCE     = 'performance';
    public const CATEGORY_INTERNATIONAL   = 'international';
    public const CATEGORY_AI_SEARCH       = 'ai_search';

    public const SEVERITIES = [
        self::SEVERITY_ERROR   => 3,
        self::SEVERITY_WARNING => 2,
        self::SEVERITY_NOTICE  => 1,
    ];

    public const CATEGORIES = [
        self::CATEGORY_CRAWLABILITY,
        self::CATEGORY_CONTENT,
        self::CATEGORY_META,
        self::CATEGORY_STRUCTURED_DATA,
        self::CATEGORY_LINKS,
        self::CATEGORY_PERFORMANCE,
        self::CATEGORY_INTERNATIONAL,
        self::CATEGORY_AI_SEARCH,
    ];

    public function __construct(
        public readonly string $code,
        public readonly string $severity,
        public readonly string $category,
        public readonly string $url,
        public readonly string $element = '',
        public readonly string $detail = '',
        public readonly string $fixHint = ''
    ) {
        if (!isset(self::SEVERITIES[$severity])) {
            throw new \InvalidArgumentException(sprintf('Unknown audit issue severity "%s".', $severity));
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown audit issue category "%s".', $category));
        }
        if (preg_match('/^[a-z][a-z0-9_]*$/', $code) !== 1) {
            throw new \InvalidArgumentException(sprintf('Audit issue code "%s" must be snake_case.', $code));
        }
    }

    public static function severityRank(string $severity): int
    {
        return self::SEVERITIES[$severity] ?? 0;
    }

    public function key(): string
    {
        return $this->code . '|' . $this->url . '|' . $this->element;
    }

    public function toArray(): array
    {
        return [
            'code'     => $this->code,
            'severity' => $this->severity,
            'category' => $this->category,
            'url'      => $this->url,
            'element'  => $this->element,
            'detail'   => $this->detail,
            'fix_hint' => $this->fixHint,
        ];
    }
}
