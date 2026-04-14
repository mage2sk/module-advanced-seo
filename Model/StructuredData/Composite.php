<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData;

use Panth\AdvancedSEO\Api\StructuredDataProviderInterface;

/**
 * Composite structured-data provider.
 *
 * Extends Aggregator (which collects all individual providers via DI) and
 * implements StructuredDataProviderInterface so that the interface can be
 * resolved from the ObjectManager.  isApplicable() always returns true;
 * getJsonLd() delegates to the aggregated build() result.
 */
class Composite extends Aggregator implements StructuredDataProviderInterface
{
    /**
     * @inheritdoc
     */
    public function isApplicable(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getJsonLd(): array
    {
        $json = $this->build();
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return 'composite';
    }
}
