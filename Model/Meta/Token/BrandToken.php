<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\RequestInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Brand\BrandDetector;

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
        if ($entity instanceof CategoryInterface) {
            $brandName = $this->brandDetector->getCurrentBrand($this->request);
            if ($brandName !== null && $brandName !== '') {
                return $brandName;
            }
        }

        if ($entity instanceof ProductInterface) {
            $value = $this->resolveFromProduct($entity);
            if ($value !== '') {
                return $value;
            }
        }

        if (isset($context['brand_name']) && is_string($context['brand_name']) && $context['brand_name'] !== '') {
            return $context['brand_name'];
        }

        $brandName = $this->brandDetector->getCurrentBrand($this->request);
        if ($brandName !== null && $brandName !== '') {
            return $brandName;
        }

        return '';
    }

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
            }
        }

        return is_scalar($raw) ? (string) $raw : '';
    }
}
