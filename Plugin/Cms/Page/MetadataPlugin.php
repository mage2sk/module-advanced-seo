<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Cms\Page;

use Magento\Cms\Helper\Page as CmsPageHelper;
use Magento\Cms\Model\PageFactory as CmsPageFactory;
use Magento\Framework\View\Result\Page as ResultPage;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Psr\Log\LoggerInterface;

class MetadataPlugin
{
    private array $identifierCache = [];

    public function __construct(
        private readonly MetaResolverInterface $metaResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly SeoConfig $seoConfig,
        private readonly LoggerInterface $logger,
        private readonly CmsPageFactory $pageFactory
    ) {
    }

    public function afterPrepareResultPage(
        CmsPageHelper $subject,
        mixed $result,
        \Magento\Framework\App\Action\Action $action,
        $pageId = null
    ): mixed {
        try {
            if (!$result instanceof ResultPage || !$this->seoConfig->isEnabled()) {
                return $result;
            }
            $storeId = (int) $this->storeManager->getStore()->getId();
            $resolvedPageId = $this->resolvePageId($pageId, $storeId);
            if ($resolvedPageId <= 0) {
                return $result;
            }
            $resolved = $this->metaResolver->resolve(
                MetaResolverInterface::ENTITY_CMS,
                $resolvedPageId,
                $storeId
            );
            $config = $result->getConfig();
            if ($resolved->getMetaTitle()) {
                $config->getTitle()->set($resolved->getMetaTitle());
            }
            if ($resolved->getMetaDescription()) {
                $config->setDescription($resolved->getMetaDescription());
            }
            if ($resolved->getMetaKeywords()) {
                $config->setKeywords($resolved->getMetaKeywords());
            }
            if ($resolved->getRobots()) {
                $config->setRobots($resolved->getRobots());
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO CMS metadata plugin failed', ['error' => $e->getMessage()]);
        }
        return $result;
    }

    private function resolvePageId(mixed $pageId, int $storeId): int
    {
        if ($pageId === null || $pageId === '') {
            return 0;
        }
        if (is_int($pageId) || (is_string($pageId) && ctype_digit($pageId))) {
            return (int) $pageId;
        }
        if (!is_string($pageId)) {
            return 0;
        }

        $identifier = $pageId;
        $delimiter = strrpos($identifier, '|');
        if ($delimiter !== false) {
            $identifier = substr($identifier, 0, $delimiter);
        }
        $cacheKey = $identifier . '@' . $storeId;
        if (isset($this->identifierCache[$cacheKey])) {
            return $this->identifierCache[$cacheKey];
        }
        try {
            $page = $this->pageFactory->create();
            $page->setStoreId($storeId)->load($identifier, 'identifier');
            $id = (int) $page->getId();
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO failed loading CMS page by identifier', [
                'identifier' => $identifier,
                'store'      => $storeId,
                'error'      => $e->getMessage(),
            ]);
            $id = 0;
        }
        $this->identifierCache[$cacheKey] = $id;
        return $id;
    }
}
