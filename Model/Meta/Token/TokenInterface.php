<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

/**
 * Internal contract for a token resolver used by TemplateRenderer.
 *
 * Implementations are declared via the `meta_tokens` DI type list on
 * Panth\AdvancedSEO\Model\Meta\TokenRegistry.
 */
interface TokenInterface
{
    /**
     * Return the scalar string value for this token given the entity
     * and the render context.
     *
     * @param mixed               $entity  The raw entity (product/category/cms/other) or null
     * @param array<string,mixed> $context Render context (store, request, params...)
     * @param string|null         $argument Optional token argument, e.g. for {{attribute:color}} => "color"
     */
    public function getValue(mixed $entity, array $context, ?string $argument = null): string;
}
