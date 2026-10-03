<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

class RobotsTxt
{
    private array $groups = [];

    private array $sitemaps = [];

    private array $contentSignals = [];

    public function __construct(private readonly string $content)
    {
        $this->parse($content);
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getGroups(): array
    {
        return $this->groups;
    }

    public function getSitemaps(): array
    {
        return $this->sitemaps;
    }

    public function getContentSignals(): array
    {
        return $this->contentSignals;
    }

    public function hasContentSignal(): bool
    {
        return $this->contentSignals !== [];
    }

    public function explicitGroupFor(string $userAgent): ?array
    {
        $agent = strtolower($userAgent);
        foreach ($this->groups as $group) {
            if (in_array($agent, $group['agents'], true)) {
                return $group;
            }
        }

        return null;
    }

    public function groupFor(string $userAgent): ?array
    {
        return $this->explicitGroupFor($userAgent) ?? $this->explicitGroupFor('*');
    }

    public function isAllowed(string $userAgent, string $path): bool
    {
        $group = $this->groupFor($userAgent);
        if ($group === null) {
            return true;
        }
        $bestLength = -1;
        $allowed    = true;
        foreach ($group['rules'] as [$type, $pattern]) {
            if ($pattern === '') {
                continue;
            }
            if (!$this->matches($pattern, $path)) {
                continue;
            }
            $length = strlen($pattern);
            if ($length > $bestLength || ($length === $bestLength && $type === 'allow')) {
                $bestLength = $length;
                $allowed    = $type === 'allow';
            }
        }

        return $allowed;
    }

    public function matchingDisallow(string $userAgent, string $path): string
    {
        $group = $this->groupFor($userAgent);
        foreach ($group['rules'] ?? [] as [$type, $pattern]) {
            if ($type === 'disallow' && $pattern !== '' && $this->matches($pattern, $path)) {
                return $pattern;
            }
        }

        return '';
    }

    private function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $pattern  = $anchored ? substr($pattern, 0, -1) : $pattern;
        $regex    = '~^' . str_replace('\*', '.*', preg_quote($pattern, '~')) . ($anchored ? '$' : '') . '~';

        return preg_match($regex, $path) === 1;
    }

    private function parse(string $content): void
    {
        $current     = null;
        $lastWasAgent = false;
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $hash = strpos($line, '#');
            if ($hash !== false) {
                $line = substr($line, 0, $hash);
            }
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if ($current === null || !$lastWasAgent) {
                    if ($current !== null) {
                        $this->groups[] = $current;
                    }
                    $current = ['agents' => [], 'rules' => [], 'contentSignals' => []];
                }
                $current['agents'][] = strtolower($value);
                $lastWasAgent = true;
                continue;
            }
            $lastWasAgent = false;

            if ($field === 'sitemap') {
                $this->sitemaps[] = $value;
                continue;
            }
            if ($field === 'content-signal') {
                $this->contentSignals[] = $value;
                if ($current !== null) {
                    $current['contentSignals'][] = $value;
                }
                continue;
            }
            if ($current !== null && in_array($field, ['allow', 'disallow'], true)) {
                $current['rules'][] = [$field, $value];
            }
        }
        if ($current !== null) {
            $this->groups[] = $current;
        }
    }
}
