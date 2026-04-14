<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData\Provider;

/**
 * Walks the media gallery of the current product and emits a VideoObject
 * node for every entry of type "external-video". Works with Magento's
 * built-in YouTube / Vimeo video gallery support.
 */
class VideoProvider extends AbstractProvider
{
    public function getCode(): string
    {
        return 'video';
    }

    public function isApplicable(): bool
    {
        return $this->getCurrentProduct() !== null;
    }

    public function getJsonLd(): array
    {
        $product = $this->getCurrentProduct();
        if ($product === null) {
            return [];
        }
        $entries = $product->getMediaGalleryEntries() ?? [];
        if ($entries === []) {
            return [];
        }
        $nodes = [];
        foreach ($entries as $entry) {
            if (!method_exists($entry, 'getExtensionAttributes')) {
                continue;
            }
            $ext = $entry->getExtensionAttributes();
            if ($ext === null || !method_exists($ext, 'getVideoContent')) {
                continue;
            }
            $video = $ext->getVideoContent();
            if ($video === null) {
                continue;
            }
            $url = (string) $video->getVideoUrl();
            if ($url === '') {
                continue;
            }
            $title = (string) ($video->getVideoTitle() ?: $entry->getLabel() ?: $product->getName());
            $description = (string) ($video->getVideoDescription() ?: $title);
            $thumbnail = (string) ($entry->getFile() ? $this->getBaseUrl() . 'media/catalog/product' . $entry->getFile() : '');

            $node = [
                '@type' => 'VideoObject',
                'name' => $title,
                'description' => $description,
                'thumbnailUrl' => $thumbnail !== '' ? $thumbnail : $url,
                'uploadDate' => gmdate('c', strtotime((string) $product->getCreatedAt()) ?: time()),
                'contentUrl' => $url,
                'embedUrl' => $url,
            ];
            $nodes[] = $node;
        }
        return $nodes;
    }
}
