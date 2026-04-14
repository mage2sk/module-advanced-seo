<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Token: {{url_key}}
 *
 * Returns the URL key of a product or category entity.
 */
class UrlKeyToken implements TokenInterface
{
    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        if ($entity instanceof ProductInterface) {
            return (string) $entity->getUrlKey();
        }

        if ($entity instanceof CategoryInterface) {
            return (string) $entity->getUrlKey();
        }

        if (is_object($entity) && method_exists($entity, 'getUrlKey')) {
            return (string) $entity->getUrlKey();
        }

        if (is_object($entity) && method_exists($entity, 'getData')) {
            return (string) ($entity->getData('url_key') ?? '');
        }

        if (is_array($entity) && isset($entity['url_key'])) {
            return (string) $entity['url_key'];
        }

        return '';
    }
}
