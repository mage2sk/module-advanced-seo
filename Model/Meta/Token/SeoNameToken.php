<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Catalog\Api\Data\ProductInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Token: {{seo_name}}
 *
 * Returns the `seo_name` EAV attribute value if set, otherwise falls back
 * to the entity name. When `panth_seo/meta/seo_name_enabled` is disabled,
 * the token ignores the dedicated `seo_name` attribute entirely and always
 * falls back to the entity's regular name, giving admins a single switch
 * to turn the SEO-name feature off globally.
 */
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

        // Honour the admin toggle — when disabled, bypass the seo_name
        // attribute entirely and always return the plain entity name.
        if ($this->config->isSeoNameEnabled($storeId)) {
            $seoName = $this->extractSeoName($entity);
            if ($seoName !== '') {
                return $seoName;
            }
        }

        // Fallback to entity name
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
