<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Generator;

use Panth\AdvancedSEO\Api\MetaGeneratorInterface;

/**
 * Safe default generator — returns empty output. Used when no AI provider is
 * configured so the pipeline never crashes.
 */
class NullAdapter implements MetaGeneratorInterface
{
    /**
     * @param array<string,mixed> $context
     * @return array{title:string, description:string, confidence:float}
     */
    public function generate(array $context, array $fields = [], array $options = []): array
    {
        return ['title' => '', 'description' => '', 'confidence' => 0.0];
    }

    public function getProvider(): string { return 'null'; }

    public function getLastUsageTokens(): int { return 0; }
}
