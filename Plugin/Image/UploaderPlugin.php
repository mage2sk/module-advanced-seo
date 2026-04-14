<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Image;

use Magento\Catalog\Model\ImageUploader;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\ImageSeo\FilenameNormalizer;
use Psr\Log\LoggerInterface;

/**
 * Normalizes uploaded catalog image filenames to SEO-friendly slugs.
 * Operates after ImageUploader::moveFileFromTmp. Best-effort: logs and
 * returns the original path if rename is not possible.
 */
class UploaderPlugin
{
    public function __construct(
        private readonly FilenameNormalizer $normalizer,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * @param ImageUploader $subject
     * @param string $result The relative path returned by moveFileFromTmp.
     * @return string
     */
    public function afterMoveFileFromTmp(ImageUploader $subject, $result)
    {
        if (!is_string($result) || $result === '') {
            return $result;
        }
        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }
        try {
            $normalizedBase = $this->normalizer->normalize(basename($result));
            if ($normalizedBase === basename($result)) {
                return $result;
            }
            $newRelative = rtrim(dirname($result), '/') . '/' . $normalizedBase;
            $mediaDir = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $basePath = method_exists($subject, 'getBasePath') ? (string) $subject->getBasePath() : '';
            if ($basePath === '') {
                return $result;
            }
            $oldAbs = $mediaDir->getAbsolutePath($basePath . '/' . ltrim($result, '/'));
            $newAbs = $mediaDir->getAbsolutePath($basePath . '/' . ltrim($newRelative, '/'));
            if ($oldAbs === $newAbs || !file_exists($oldAbs) || file_exists($newAbs)) {
                return $result;
            }
            try {
                if (rename($oldAbs, $newAbs)) {
                    return $newRelative;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[PanthSEO] image rename failed: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] image filename normalize failed: ' . $e->getMessage());
        }
        return $result;
    }
}
