<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Image;

use Magento\Catalog\Block\Product\View\Gallery;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\ImageSeo\ImageTemplateResolver;
use Psr\Log\LoggerInterface;

/**
 * Plugin on Magento\Catalog\Block\Product\View\Gallery::getGalleryImagesJson.
 *
 * Injects template-rendered alt and title text into every gallery image's
 * "caption" field. The native Magento gallery JSON uses "caption" for the
 * alt/title of each image (rendered into both the alt attribute and the
 * caption overlay by the fotorama/gallery widget).
 *
 * When a gallery image already has a per-image label set by the merchant,
 * that label is preserved. Only empty or product-name-only captions are
 * upgraded to the template-rendered text.
 *
 * The title is added as a separate "title" key in each image object so
 * frontend templates can distinguish between alt and title if needed.
 */
class GalleryImageSeoPlugin
{
    public function __construct(
        private readonly ImageTemplateResolver $templateResolver,
        private readonly LoggerInterface $logger,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * @param Gallery $subject
     * @param string  $result  JSON-encoded gallery images array
     * @return string
     */
    public function afterGetGalleryImagesJson(Gallery $subject, $result): string
    {
        if (!is_string($result) || $result === '') {
            return (string) $result;
        }

        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }

        if (!$this->templateResolver->isGalleryEnabled()) {
            return $result;
        }

        try {
            $product = $subject->getProduct();
            if ($product === null) {
                return $result;
            }

            $images = json_decode($result, true);
            if (!is_array($images) || $images === []) {
                return $result;
            }

            $resolved = $this->templateResolver->resolve($product);
            $alt   = $resolved['alt'] ?? '';
            $title = $resolved['title'] ?? '';
            $productName = (string) $product->getName();

            foreach ($images as $index => &$image) {
                if (!is_array($image)) {
                    continue;
                }

                $currentCaption = trim((string) ($image['caption'] ?? ''));

                // Only override empty captions or those that are just the
                // raw product name (i.e. the Magento default fallback).
                // Merchant-set per-image labels are preserved.
                if ($currentCaption === '' || $currentCaption === $productName) {
                    if ($alt !== '') {
                        $image['caption'] = $this->appendPosition($alt, $index, count($images));
                    }
                }

                // Always inject a "title" key for frontend consumption.
                // Per-image label takes precedence over template title.
                $currentTitle = trim((string) ($image['title'] ?? ''));
                if ($currentTitle === '' || $currentTitle === $productName) {
                    $image['title'] = $title !== '' ? $title : ($image['caption'] ?? $productName);
                }
            }
            unset($image);

            $encoded = json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                return $result;
            }

            return $encoded;
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[PanthSEO] GalleryImageSeoPlugin failed',
                ['error' => $e->getMessage()]
            );
        }

        return $result;
    }

    /**
     * For galleries with multiple images, append a position suffix to
     * differentiate alt text (e.g. "Product Name - Image 2 of 5").
     * Single-image galleries get no suffix.
     */
    private function appendPosition(string $alt, int $index, int $total): string
    {
        if ($total <= 1) {
            return $alt;
        }

        return $alt . ' - Image ' . ($index + 1) . ' of ' . $total;
    }
}
