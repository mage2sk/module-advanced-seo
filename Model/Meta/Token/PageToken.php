<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Cms\Api\Data\PageInterface;

/**
 * {{page}} — CMS page title when entity is a CMS page,
 * otherwise the current pagination page number.
 */
class PageToken implements TokenInterface
{
    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        // If entity is a CMS page, return its title
        if ($entity instanceof PageInterface) {
            return (string) $entity->getTitle();
        }

        // If entity has getTitle (generic CMS-like object)
        if (is_object($entity) && method_exists($entity, 'getTitle')) {
            $title = $entity->getTitle();
            if ($title !== null && $title !== '') {
                return (string) $title;
            }
        }

        // Fallback: pagination page number
        $page = (int) ($context['page'] ?? 0);
        if ($page <= 1) {
            return '';
        }
        return (string) $page;
    }
}
