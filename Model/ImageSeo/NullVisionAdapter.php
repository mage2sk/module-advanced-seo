<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\ImageSeo;

/**
 * Default no-op vision adapter. Ships disabled; replace via DI preference
 * to plug in a real OpenAI / Claude vision implementation.
 */
class NullVisionAdapter implements VisionAdapterInterface
{
    public function describe(string $absoluteImagePath, array $context = []): ?array
    {
        return null;
    }
}
