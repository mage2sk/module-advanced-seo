<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Catalog\Api\Data\ProductInterface;

class SkuToken implements TokenInterface
{
    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        if ($entity instanceof ProductInterface) {
            return (string) $entity->getSku();
        }
        if (is_object($entity) && method_exists($entity, 'getSku')) {
            return (string) $entity->getSku();
        }
        return '';
    }
}
