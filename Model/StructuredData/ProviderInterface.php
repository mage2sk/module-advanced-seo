<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData;

use Panth\AdvancedSEO\Api\StructuredDataProviderInterface;

/**
 * Local alias for the public API provider contract so that internal
 * collaborators can type-hint `Model\StructuredData\ProviderInterface`
 * without reaching into the Api namespace.
 */
interface ProviderInterface extends StructuredDataProviderInterface
{
}
