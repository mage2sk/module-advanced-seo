<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api;

/**
 * Contract for AI meta generation adapters (OpenAI / Claude / Null).
 */
interface MetaGeneratorInterface
{
    public const FIELD_TITLE       = 'title';
    public const FIELD_DESCRIPTION = 'description';
    public const FIELD_KEYWORDS    = 'keywords';

    /**
     * Generate meta fields for a single entity.
     *
     * @param array<string,mixed> $context   Entity context (name, description, attributes...)
     * @param string[]            $fields    Fields to generate (see FIELD_* constants)
     * @param array<string,mixed> $options   Adapter options (locale, tone, maxTokens...)
     * @return array<string,string>          Map of field => generated text
     */
    public function generate(array $context, array $fields, array $options = []): array;

    /**
     * @return string Provider identifier ("openai", "claude", "null").
     */
    public function getProvider(): string;

    /**
     * @return int Approximate tokens consumed by the last generate() call.
     */
    public function getLastUsageTokens(): int;
}
