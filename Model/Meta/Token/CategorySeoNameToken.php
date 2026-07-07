<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Catalog\Api\Data\CategoryInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

class CategorySeoNameToken implements TokenInterface
{
    public function __construct(
        private readonly SeoConfig $config
    ) {
    }

    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        if (!$entity instanceof CategoryInterface) {
            return '';
        }

        $storeId = isset($context['store_id']) ? (int) $context['store_id'] : null;
        if ($this->config->isSeoNameEnabled($storeId)) {
            $seoName = $this->resolveSeoName($entity);
            if ($seoName !== '') {
                return $seoName;
            }
        }

        return (string) $entity->getName();
    }

    private function resolveSeoName(CategoryInterface $entity): string
    {
        if (!method_exists($entity, 'getData')) {
            return '';
        }

        $value = $entity->getData('seo_name');

        if ($value === null || (string) $value === '') {
            return '';
        }

        return (string) $value;
    }
}
