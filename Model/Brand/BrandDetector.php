<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Brand;

use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Psr\Log\LoggerInterface;

class BrandDetector
{
    private array $brandPageCache = [];

    private array $brandNameCache = [];

    public function __construct(
        private readonly SeoConfig $seoConfig,
        private readonly LayerResolver $layerResolver,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isBrandPage(RequestInterface $request): bool
    {
        $cacheKey = $this->buildCacheKey($request);
        if (isset($this->brandPageCache[$cacheKey])) {
            return $this->brandPageCache[$cacheKey];
        }

        $brandAttribute = $this->getBrandAttributeCode();
        if ($brandAttribute === '') {
            $this->brandPageCache[$cacheKey] = false;
            return false;
        }

        $paramValue = $request->getParam($brandAttribute);
        $this->brandPageCache[$cacheKey] = $paramValue !== null && $paramValue !== '';
        return $this->brandPageCache[$cacheKey];
    }

    public function getCurrentBrand(RequestInterface $request): ?string
    {
        $cacheKey = $this->buildCacheKey($request);
        if (array_key_exists($cacheKey, $this->brandNameCache)) {
            return $this->brandNameCache[$cacheKey];
        }

        if (!$this->isBrandPage($request)) {
            $this->brandNameCache[$cacheKey] = null;
            return null;
        }

        $brandAttribute = $this->getBrandAttributeCode();

        $label = $this->resolveFromLayerState($brandAttribute);
        if ($label !== null) {
            $this->brandNameCache[$cacheKey] = $label;
            return $label;
        }

        $rawValue = (string) $request->getParam($brandAttribute, '');
        if ($rawValue !== '') {
            $label = $this->resolveOptionLabel($brandAttribute, $rawValue);
            if ($label !== null) {
                $this->brandNameCache[$cacheKey] = $label;
                return $label;
            }
        }

        $this->brandNameCache[$cacheKey] = null;
        return null;
    }

    private function getBrandAttributeCode(): string
    {
        return $this->seoConfig->getBrandAttribute();
    }

    private function resolveFromLayerState(string $attributeCode): ?string
    {
        try {
            $layer = $this->layerResolver->get();
            $state = $layer->getState();
            foreach ($state->getFilters() as $filterItem) {
                $requestVar = $filterItem->getFilter()->getRequestVar();
                if ($requestVar === $attributeCode) {
                    $label = $filterItem->getLabel();
                    if (is_array($label)) {
                        return implode(', ', array_map('strval', $label));
                    }
                    return (string) $label;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function resolveOptionLabel(string $attributeCode, string $rawValue): ?string
    {
        try {
            $attribute = $this->attributeRepository->get(
                \Magento\Catalog\Api\Data\ProductAttributeInterface::ENTITY_TYPE_CODE,
                $attributeCode
            );

            if (!$attribute->usesSource()) {
                return $rawValue;
            }

            $source = $attribute->getSource();
            $optionIds = explode(',', $rawValue);
            $labels = [];
            foreach ($optionIds as $optionId) {
                $optionId = trim($optionId);
                if ($optionId === '') {
                    continue;
                }
                $text = $source->getOptionText($optionId);
                if (is_array($text)) {
                    $labels[] = implode(', ', array_map('strval', $text));
                } elseif (is_string($text) && $text !== '') {
                    $labels[] = $text;
                }
            }

            return $labels !== [] ? implode(', ', $labels) : null;
        } catch (\Throwable $e) {
            $this->logger->debug('Panth SEO BrandDetector: option label resolution failed', [
                'attribute' => $attributeCode,
                'value'     => $rawValue,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function buildCacheKey(RequestInterface $request): string
    {
        $brandAttribute = $this->getBrandAttributeCode();
        $paramValue = (string) $request->getParam($brandAttribute, '');
        return $brandAttribute . '::' . $paramValue;
    }
}
