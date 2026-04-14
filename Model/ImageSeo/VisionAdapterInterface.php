<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\ImageSeo;

/**
 * Pluggable LLM-vision adapter for alt-text generation.
 * Implementations may call OpenAI Vision, Claude Vision, etc. Adapters must
 * be fast-failing and MUST NOT block page rendering — Panth_AdvancedSEO
 * only calls them from CLI/queue contexts.
 */
interface VisionAdapterInterface
{
    /**
     * @return array{alt:string,title?:string}|null
     */
    public function describe(string $absoluteImagePath, array $context = []): ?array;
}
