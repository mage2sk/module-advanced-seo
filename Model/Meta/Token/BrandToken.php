<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\RequestInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Brand\BrandDetector;

/**
 * {{brand}} -- resolves to the brand / manufacturer name.
 *
 * Resolution strategy (in order of priority):
 *  1. Category page with an active brand filter: returns the filtered brand name.
 *  2. Product entity: reads the configured brand attribute value from the product.
 *  3. Context key `brand_name`: allows callers to inject the brand externally.
 *
 * The brand attribute code is read from `panth_seo/structured_data/brand_attribute`
 * (defaults to "manufacturer").
 */
class BrandToken implements TokenInterface
{
    public function __construct(
        private readonly SeoConfig $seoConfig,
        private readonly BrandDetector $brandDetector,
        private readonly RequestInterface $request
    ) {
    }

    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        // 1. Category context with brand filter active -- highest priority.
        if ($entity instanceof CategoryInterface) {
            $brandName = $this->brandDetector->getCurrentBrand($this->request);
            if ($brandName !== null && $brandName !== '') {
                return $brandName;
            }
        }

        // 2. Product context -- read the brand attribute directly.
        if ($entity instanceof ProductInterface) {
            $value = $this->resolveFromProduct($entity);
            if ($value !== '') {
                return $value;
            }
        }

        // 3. Externally injected brand name via render context.
        if (isset($context['brand_name']) && is_string($context['brand_name']) && $context['brand_name'] !== '') {
            return $context['brand_name'];
        }

        // 4. Last resort: check brand filter on any page type.
        $brandName = $this->brandDetector->getCurrentBrand($this->request);
        if ($brandName !== null && $brandName !== '') {
            return $brandName;
        }

        return '';
    }

    /**
     * Read the configured brand attribute value from a product entity.
     */
    private function resolveFromProduct(ProductInterface $product): string
    {
        $attributeCode = $this->seoConfig->getBrandAttribute();
        if ($attributeCode === '') {
            return '';
        }

        $customAttr = $product->getCustomAttribute($attributeCode);
        $raw = $customAttr !== null ? $customAttr->getValue() : null;

        if ($raw === null || $raw === '') {
            if (method_exists($product, 'getData')) {
                $raw = $product->getData($attributeCode);
            }
        }

        if ($raw === null || $raw === '') {
            return '';
        }

        // Resolve option label for select/multiselect attributes.
        if (method_exists($product, 'getResource')) {
            try {
                $resource = $product->getResource();
                if ($resource !== null && method_exists($resource, 'getAttribute')) {
                    $attribute = $resource->getAttribute($attributeCode);
                    if ($attribute && $attribute->usesSource()) {
                        $label = $attribute->getSource()->getOptionText($raw);
                        if (is_array($label)) {
                            $label = implode(', ', array_map('strval', $label));
                        }
                        if (is_string($label) && $label !== '') {
                            return $label;
                        }
                    }
                }
            } catch (\Throwable) {
                // fall through to raw value
            }
        }

        return is_scalar($raw) ? (string) $raw : '';
    }
}
