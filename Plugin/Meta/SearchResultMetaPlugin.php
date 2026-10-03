<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Meta;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Escaper;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Psr\Log\LoggerInterface;

class SearchResultMetaPlugin
{
    private const ENTITY_TYPE = 'search';

    private const DEFAULT_DESCRIPTION = 'Find %s and related products in our store. Browse our full selection.';

    private bool $applied = false;

    public function __construct(
        private readonly TemplateRenderer $templateRenderer,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly SeoConfig $seoConfig,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function beforePublicBuild(PageConfig $subject): array
    {
        if ($this->applied) {
            return [];
        }

        try {
            if (!$this->seoConfig->isEnabled()) {
                return [];
            }

            if (!$this->isSearchResultRequest()) {
                return [];
            }

            $this->applied = true;

            $storeId  = (int) $this->storeManager->getStore()->getId();
            $template = $this->loadTemplate($storeId);

            $searchQuery = (string) $this->request->getParam('q', '');
            $context     = [
                'store_id'     => $storeId,
                'search_query' => $searchQuery,
            ];

            $titlePattern = (string) ($template['meta_title'] ?? '');
            if ($titlePattern !== '') {
                $renderedTitle = $this->templateRenderer->render($titlePattern, null, $context);
                if ($renderedTitle !== '') {
                    $subject->getTitle()->set($renderedTitle);
                }
            }

            $renderedDesc = '';
            $descPattern  = (string) ($template['meta_description'] ?? '');
            if ($descPattern !== '') {
                $renderedDesc = $this->templateRenderer->render($descPattern, null, $context);
            }

            if ($renderedDesc === '') {
                $renderedDesc = $this->buildDefaultDescription($searchQuery);
            }

            if ($renderedDesc !== '') {
                $subject->setDescription($renderedDesc);
            }

            $robotsValue = (string) ($template['robots'] ?? '');
            if ($robotsValue !== '') {
                $subject->setRobots($robotsValue);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Panth SEO search result meta plugin failed',
                ['error' => $e->getMessage()]
            );
        }

        return [];
    }

    private function isSearchResultRequest(): bool
    {
        $path = (string) $this->request->getPathInfo();
        if ($path === '') {
            return false;
        }
        return str_contains($path, '/catalogsearch/');
    }

    private function buildDefaultDescription(string $searchQuery): string
    {
        $trimmed = trim($searchQuery);
        if ($trimmed === '') {
            return 'Browse our full selection of products in our store.';
        }

        if (mb_strlen($trimmed) > 80) {
            $trimmed = mb_substr($trimmed, 0, 80);
        }

        $safeQuery = $this->escaper->escapeHtml($trimmed);

        return sprintf(self::DEFAULT_DESCRIPTION, $safeQuery);
    }

    private function loadTemplate(int $storeId): ?array
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_template');

        $select = $conn->select()
            ->from($table)
            ->where('entity_type = ?', self::ENTITY_TYPE)
            ->where('is_active = ?', 1)
            ->where('store_id IN (?)', [0, $storeId])
            ->order(['store_id DESC', 'priority ASC'])
            ->limit(1);

        $row = $conn->fetchRow($select);

        return is_array($row) && !empty($row) ? $row : null;
    }
}
