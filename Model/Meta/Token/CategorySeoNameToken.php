<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Catalog\Api\Data\CategoryInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Token: {{category_seo_name}}
 *
 * Resolves the `seo_name` EAV attribute of a category entity. When the
 * attribute is empty or the entity is not a category, the token falls back
 * to {@see CategoryInterface::getName()}. Honours the global
 * `panth_seo/meta/seo_name_enabled` flag: when it is off, the dedicated
 * attribute is ignored entirely.
 */
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
        /** @var \Magento\Catalog\Model\Category $entity */
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
