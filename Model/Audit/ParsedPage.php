<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

class ParsedPage
{
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly array $headers = [],
        public readonly float $ttfbMs = 0.0,
        public readonly float $totalMs = 0.0,
        public readonly int $htmlBytes = 0,
        public readonly string $title = '',
        public readonly string $metaDescription = '',
        public readonly array $h1 = [],
        public readonly string $canonical = '',
        public readonly string $robotsMeta = '',
        public readonly string $xRobotsTag = '',
        public readonly array $hreflang = [],
        public readonly array $links = [],
        public readonly array $images = [],
        public readonly array $jsonLd = [],
        public readonly array $scripts = [],
        public readonly array $styles = [],
        public readonly int $textBytes = 0,
        public readonly array $jsonLdBlocks = [],
        public readonly array $inlineBytes = [],
        public readonly array $landmarks = [],
        public readonly float $divSpanTextShare = 0.0,
        public readonly bool $hasVisibleDate = false,
        public readonly bool $hasFaqOrHowTo = false,
        public readonly string $mainText = '',
        public readonly bool $timingCacheBusted = false
    ) {
    }

    public function header(string $name): string
    {
        return (string) ($this->headers[strtolower($name)] ?? '');
    }

    public function isHtml(): bool
    {
        $type = $this->header('content-type');

        return $type === '' || stripos($type, 'html') !== false;
    }

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isNoindex(): bool
    {
        return stripos($this->robotsMeta, 'noindex') !== false || stripos($this->xRobotsTag, 'noindex') !== false;
    }

    public function isIndexable(): bool
    {
        if (!$this->isOk() || !$this->isHtml() || $this->isNoindex()) {
            return false;
        }
        if ($this->canonical === '') {
            return true;
        }

        return UrlHelper::normalize($this->canonical) === UrlHelper::normalize($this->url);
    }

    public function withTiming(float $ttfbMs, float $totalMs): self
    {
        $values = get_object_vars($this);
        $values['ttfbMs']            = $ttfbMs;
        $values['totalMs']           = $totalMs;
        $values['timingCacheBusted'] = true;

        return new self(...$values);
    }
}
