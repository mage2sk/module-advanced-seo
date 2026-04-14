<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Sitemap;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Filesystem;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\SitemapBuilderInterface;

/**
 * Streams `/panth-sitemap.xml`. Delegates to the SitemapBuilderInterface to
 * generate the XML body. Falls back to the first Magento sitemap file for the
 * current store so the URL always resolves.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Filesystem $filesystem,
        private readonly SitemapBuilderInterface $sitemapBuilder
    ) {
    }

    public function execute(): ResponseInterface|ResultInterface
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $body = $this->tryCustomBuilder($storeId);
        if ($body === null) {
            $body = $this->tryMagentoSitemapFile();
        }
        if ($body === null) {
            $body = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>' . "\n";
        }

        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'application/xml; charset=utf-8', true);
        $result->setContents($body);
        return $result;
    }

    private function tryCustomBuilder(int $storeId): ?string
    {
        try {
            $out = $this->sitemapBuilder->buildForStore($storeId);
            return $out !== '' ? $out : null;
        } catch (\Throwable) {
        }
        return null;
    }

    private function tryMagentoSitemapFile(): ?string
    {
        try {
            $pub = $this->filesystem->getDirectoryRead(DirectoryList::PUB);
            foreach (['sitemap.xml', 'sitemap/sitemap.xml'] as $candidate) {
                if ($pub->isFile($candidate)) {
                    return $pub->readFile($candidate);
                }
            }
        } catch (\Throwable) {
        }
        return null;
    }
}
