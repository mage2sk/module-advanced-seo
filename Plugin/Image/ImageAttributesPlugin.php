<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Image;

use Magento\Catalog\Helper\Image as ImageHelper;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\ImageSeo\ImageTemplateResolver;
use Psr\Log\LoggerInterface;

/**
 * Plugin on Magento\Catalog\Helper\Image::getLabel.
 *
 * When the image alt/title template feature is enabled in config
 * (panth_seo/image/image_seo_enabled), replaces the default image
 * label with a template-rendered alt text that can include tokens
 * like {{name}}, {{store}}, {{sku}}, {{category}}, etc.
 *
 * This plugin has a higher sortOrder than the existing
 * ProductImagePlugin so it takes precedence. If the template
 * resolver is disabled or fails, it falls back gracefully to
 * whatever the previous result was (which may already be enriched
 * by ProductImagePlugin).
 *
 * Registered via di.xml with sortOrder="20" (ProductImagePlugin is
 * at the default sortOrder).
 */
class ImageAttributesPlugin
{
    public function __construct(
        private readonly ImageTemplateResolver $templateResolver,
        private readonly LoggerInterface $logger,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * @param ImageHelper $subject
     * @param mixed       $result  The label string from Magento or the earlier plugin
     * @return mixed
     */
    public function afterGetLabel(ImageHelper $subject, mixed $result): mixed
    {
        if (!$this->seoConfig->isEnabled() || !$this->templateResolver->isEnabled()) {
            return $result;
        }

        try {
            $product = $this->extractProduct($subject);
            if ($product === null) {
                return $result;
            }

            $alt = $this->templateResolver->getAlt($product);

            if ($alt !== '') {
                return $alt;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[PanthSEO] ImageAttributesPlugin::afterGetLabel failed',
                ['error' => $e->getMessage()]
            );
        }

        return $result;
    }

    /**
     * Read the protected _product property from the image helper.
     *
     * Magento\Catalog\Helper\Image::getProduct() is protected, so we
     * access the backing property through a reflection handle cached
     * across calls. Returns null if the property is missing or empty.
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
