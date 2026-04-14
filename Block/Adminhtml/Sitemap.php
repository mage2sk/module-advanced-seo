<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

class Sitemap extends Template
{
    protected $_template = 'Panth_AdvancedSEO::sitemap.phtml';

    public function __construct(
        Context $context,
        private readonly Filesystem $filesystem,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getSitemapFiles(): array
    {
        $pubDir = $this->filesystem->getDirectoryRead(DirectoryList::PUB);
        $files = [];
        foreach (['sitemap.xml', 'sitemap/sitemap.xml'] as $path) {
            if ($pubDir->isExist($path)) {
                $stat = $pubDir->stat($path);
                $files[] = [
                    'path' => $path,
                    'size' => $stat['size'] ?? 0,
                    'modified' => isset($stat['mtime']) ? date('Y-m-d H:i:s', (int)$stat['mtime']) : 'N/A',
                ];
            }
        }
        return $files;
    }

    public function getGenerateUrl(): string
    {
        return $this->getUrl('panth_seo/sitemap/generate');
    }
}
