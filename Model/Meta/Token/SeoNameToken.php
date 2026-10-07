<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Catalog\Api\Data\ProductInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

class SeoNameToken implements TokenInterface
{
    public function __construct(
        private readonly SeoConfig $config
    ) {
    }

    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        if ($entity === null) {
            return '';
        }

        $storeId = isset($context['store_id']) ? (int) $context['store_id'] : null;

        if ($this->config->isSeoNameEnabled($storeId)) {
            $seoName = $this->extractSeoName($entity);
            if ($seoName !== '') {
                return $seoName;
            }
        }

        return $this->extractName($entity);
    }

    private function extractSeoName(mixed $entity): string
    {
        if (is_object($entity) && method_exists($entity, 'getData')) {
            $value = $entity->getData('seo_name');
            if ($value !== null && (string) $value !== '') {
                return (string) $value;
            }
        }

        if (is_array($entity) && isset($entity['seo_name']) && (string) $entity['seo_name'] !== '') {
            return (string) $entity['seo_name'];
        }

        return '';
    }

    private function extractName(mixed $entity): string
    {
        if ($entity instanceof ProductInterface) {
            return (string) $entity->getName();
        }

        if (is_object($entity) && method_exists($entity, 'getName')) {
            return (string) $entity->getName();
        }

        if (is_object($entity) && method_exists($entity, 'getTitle')) {
            return (string) $entity->getTitle();
        }

        if (is_array($entity) && isset($entity['name'])) {
            return (string) $entity['name'];
        }

        return '';
    }
}
