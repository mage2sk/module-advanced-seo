<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Cms\Api\Data\PageInterface;

class NameToken implements TokenInterface
{
    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        if ($entity instanceof ProductInterface) {
            return (string) $entity->getName();
        }
        if ($entity instanceof CategoryInterface) {
            return (string) $entity->getName();
        }
        if ($entity instanceof PageInterface) {
            return (string) $entity->getTitle();
        }
        if (is_object($entity) && method_exists($entity, 'getName')) {
            return (string) $entity->getName();
        }
        if (is_array($entity) && isset($entity['name'])) {
            return (string) $entity['name'];
        }
        return '';
    }
}
