<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api;

/**
 * Contributes a Schema.org JSON-LD block to the current page.
 *
 * Implementations are registered as a list on the composite provider in di.xml.
 */
interface StructuredDataProviderInterface
{
    /**
     * @return bool True if this provider wants to emit JSON-LD on the current request.
     */
    public function isApplicable(): bool;

    /**
     * @return array<string,mixed> A JSON-LD graph node (must include "@context" and "@type").
     */
    public function getJsonLd(): array;

    /**
     * @return string Unique identifier (e.g. "product", "breadcrumb", "organization").
     */
    public function getCode(): string;
}
