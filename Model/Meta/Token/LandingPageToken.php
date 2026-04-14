<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Cms\Api\Data\PageInterface;
use Panth\AdvancedSEO\Model\LandingPage\LandingPageDetector;

/**
 * {{landing:heading}}    -- resolves to content_heading of the CMS page
 * {{landing:identifier}} -- resolves to the page identifier (URL key)
 *
 * Returns an empty string when the current entity is not a CMS page or is
 * not detected as a landing page by {@see LandingPageDetector}.
 */
class LandingPageToken implements TokenInterface
{
    public function __construct(
        private readonly LandingPageDetector $detector
    ) {
    }

    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        if (!$entity instanceof PageInterface) {
            return '';
        }

        if (!$this->detector->isLandingPage($entity)) {
            return '';
        }

        return match (strtolower($argument ?? '')) {
            'heading'    => (string) $entity->getContentHeading(),
            'identifier' => (string) $entity->getIdentifier(),
            default      => '',
        };
    }
}
