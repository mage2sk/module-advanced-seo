<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Image;

use Magento\Catalog\Helper\Image as ImageHelper;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Psr\Log\LoggerInterface;

/**
 * Theme-agnostic plugin on Magento\Catalog\Helper\Image::getLabel that
 * falls back to a generated alt label when the stored image label is
 * empty. Works in both Hyva and Luma (no JS dependencies).
 */
class ProductImagePlugin
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * @param ImageHelper $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGetLabel(ImageHelper $subject, $result)
    {
        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }
        if (is_string($result) && trim($result) !== '') {
            return $result;
        }
        try {
            $product = $this->extractProduct($subject);
            if ($product !== null) {
                $name = (string) $product->getName();
                if ($name !== '') {
                    return $name;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] getLabel fallback failed: ' . $e->getMessage());
        }
        return $result;
    }

    /**
     * Read the protected _product property from the image helper via
     * a cached ReflectionProperty. Helper\Image::getProduct() itself is
     * protected and cannot be called from a plugin.
     */
    private function extractProduct(ImageHelper $subject): ?\Magento\Catalog\Api\Data\ProductInterface
    {
        static $property = null;
        if ($property === null) {
            try {
                $property = new \ReflectionProperty(ImageHelper::class, '_product');
                $property->setAccessible(true);
            } catch (\Throwable $e) {
                return null;
            }
        }

        $product = $property->getValue($subject);
        return $product instanceof \Magento\Catalog\Api\Data\ProductInterface ? $product : null;
    }
}
