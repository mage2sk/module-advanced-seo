<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Cms\Api\Data\PageInterface;

class PageToken implements TokenInterface
{
    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        if ($entity instanceof PageInterface) {
            return (string) $entity->getTitle();
        }

        if (is_object($entity) && method_exists($entity, 'getTitle')) {
            $title = $entity->getTitle();
            if ($title !== null && $title !== '') {
                return (string) $title;
            }
        }

        $page = (int) ($context['page'] ?? 0);
        if ($page <= 1) {
            return '';
        }
        return (string) $page;
    }
}
