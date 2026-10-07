<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

class ProbeResult
{
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly string $error = '',
        public readonly bool $unverifiable = false,
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly float $ttfbMs = 0.0,
        public readonly float $totalMs = 0.0,
        public readonly string $effectiveUrl = ''
    ) {
    }

    public function header(string $name): string
    {
        return (string) ($this->headers[strtolower($name)] ?? '');
    }

    public function isBroken(): bool
    {
        if ($this->unverifiable) {
            return false;
        }

        return $this->status === 0 || $this->status >= 400;
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    public function location(): string
    {
        return $this->header('location');
    }
}
