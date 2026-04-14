<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;

class PriceToken implements TokenInterface
{
    public function __construct(
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        if (!$entity instanceof ProductInterface) {
            return '';
        }
        $price = (float) $entity->getFinalPrice() ?: (float) $entity->getPrice();
        if ($price <= 0.0) {
            return '';
        }
        try {
            return (string) $this->priceCurrency->format($price, false);
        } catch (\Throwable) {
            return number_format($price, 2, '.', '');
        }
    }
}
