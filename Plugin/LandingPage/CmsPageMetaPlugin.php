<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\LandingPage;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Controller\Page\View as CmsPageView;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Result\Page as ResultPage;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\LandingPage\LandingPageDetector;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Panth\AdvancedSEO\Model\ResourceModel\Template\CollectionFactory as TemplateCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * After-plugin on {@see \Magento\Cms\Controller\Page\View::execute()}.
 *
 * When the rendered CMS page qualifies as a landing page, this plugin looks
 * for a `landing_page` entity-type template in `panth_seo_template` and
 * renders it against the CMS page entity. If no dedicated template exists the
 * regular CMS metadata (already applied by the core CMS metadata plugin) is
 * left untouched.
 */
class CmsPageMetaPlugin
{
    /** Entity type stored in panth_seo_template for landing pages. */
    private const ENTITY_TYPE_LANDING_PAGE = 'landing_page';

    public function __construct(
        private readonly LandingPageDetector $detector,
        private readonly TemplateCollectionFactory $templateCollectionFactory,
        private readonly TemplateRenderer $renderer,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly SeoConfig $seoConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterExecute(CmsPageView $subject, mixed $result): mixed
    {
        try {
            if (!$result instanceof ResultPage || !$this->seoConfig->isEnabled()) {
                return $result;
            }

            $pageId = (int) $this->request->getParam('page_id', 0);
            if ($pageId <= 0) {
                return $result;
            }

            $page = $this->pageRepository->getById($pageId);
            if (!$this->detector->isLandingPage($page)) {
                return $result;
            }

            $storeId  = (int) $this->storeManager->getStore()->getId();
            $template = $this->loadLandingPageTemplate($storeId);

            if ($template === null) {
                return $result;
            }

            $context = [
                'store_id'    => $storeId,
                'entity_type' => self::ENTITY_TYPE_LANDING_PAGE,
                'entity_id'   => $pageId,
            ];

            $this->applyTemplate($result, $template, $page, $context);
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO landing page meta plugin failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Load the best-matching landing_page template for the given store.
     *
     * @return array<string, mixed>|null
     */
    private function loadLandingPageTemplate(int $storeId): ?array
    {
        if (!$this->seoConfig->useTemplates($storeId)) {
            return null;
        }

        $collection = $this->templateCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1)
            ->addFieldToFilter('entity_type', self::ENTITY_TYPE_LANDING_PAGE)
            ->addFieldToFilter('store_id', ['in' => [$storeId, 0]])
            ->setOrder('store_id', 'DESC')
            ->setOrder('priority', 'ASC')
            ->setPageSize(1);

        $item = $collection->getFirstItem();
        if (!$item->getId()) {
            return null;
        }

        return $item->getData();
    }

    /**
     * Render the template fields and apply them to the page config.
     *
     * @param array<string, mixed> $template
     * @param array<string, mixed> $context
     */
    private function applyTemplate(
        ResultPage $resultPage,
        array $template,
        PageInterface $page,
        array $context
    ): void {
        $config = $resultPage->getConfig();

        if (!empty($template['meta_title'])) {
            $title = $this->renderer->render((string) $template['meta_title'], $page, $context);
            if ($title !== '') {
                $config->getTitle()->set($title);
            }
        }

        if (!empty($template['meta_description'])) {
            $desc = $this->renderer->render((string) $template['meta_description'], $page, $context);
            if ($desc !== '') {
                $config->setDescription($desc);
            }
        }

        if (!empty($template['meta_keywords'])) {
            $kw = $this->renderer->render((string) $template['meta_keywords'], $page, $context);
            if ($kw !== '') {
                $config->setKeywords($kw);
            }
        }

        if (!empty($template['robots'])) {
            $config->setRobots((string) $template['robots']);
        }
    }
}
