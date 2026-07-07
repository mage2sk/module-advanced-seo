<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Meta;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Page\Config\Renderer as HeadRenderer;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

class RelPrevNextPlugin
{
    private const XML_PAGINATION_POSITION = 'panth_seo/meta/pagination_position';
    private const POSITION_NONE           = 'none';

    private const ELIGIBLE_ACTIONS = [
        'catalog_category_view',
        'catalogsearch_result_index',
    ];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlInterface $urlBuilder,
        private readonly State $appState,
        private readonly SeoConfig $seoConfig
    ) {
    }

    public function afterRenderHeadContent(HeadRenderer $subject, string $result): string
    {
        if (!$this->shouldProcess()) {
            return $result;
        }

        $currentPage = $this->getCurrentPage();
        $links       = '';

        if ($currentPage > 1) {
            $prevUrl = $this->buildPageUrl($currentPage - 1);
            $links  .= sprintf(
                '<link rel="prev" href="%s" />' . "\n",
                $this->escapeUrl($prevUrl)
            );
        }

        $nextUrl = $this->buildPageUrl($currentPage + 1);
        $links  .= sprintf(
            '<link rel="next" href="%s" />' . "\n",
            $this->escapeUrl($nextUrl)
        );

        return $result . $links;
    }

    private function shouldProcess(): bool
    {
        if (!$this->seoConfig->isEnabled()) {
            return false;
        }

        if (!$this->isFrontend()) {
            return false;
        }

        if ($this->getPaginationPosition() === self::POSITION_NONE) {
            return false;
        }

        if (!$this->isEligibleAction()) {
            return false;
        }

        return $this->getCurrentPage() >= 1;
    }

    private function getCurrentPage(): int
    {
        return max(1, (int) $this->request->getParam('p', 1));
    }

    private function buildPageUrl(int $page): string
    {
        $currentUrl = $this->urlBuilder->getCurrentUrl();

        $parts = parse_url($currentUrl);
        if ($parts === false) {
            return $currentUrl;
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        if ($page <= 1) {
            unset($query['p']);
        } else {
            $query['p'] = (string) $page;
        }

        $scheme   = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host     = $parts['host'] ?? '';
        $port     = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path     = $parts['path'] ?? '/';
        $qs       = $query !== [] ? '?' . http_build_query($query) : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $host . $port . $path . $qs . $fragment;
    }

    private function escapeUrl(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    private function getFullActionName(): string
    {
        if ($this->request instanceof \Magento\Framework\App\Request\Http) {
            return $this->request->getFullActionName();
        }

        $route      = $this->request->getModuleName() ?? '';
        $controller = $this->request->getControllerName() ?? '';
        $action     = $this->request->getActionName() ?? '';

        return $route . '_' . $controller . '_' . $action;
    }

    private function isEligibleAction(): bool
    {
        return in_array($this->getFullActionName(), self::ELIGIBLE_ACTIONS, true);
    }

    private function getPaginationPosition(): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PAGINATION_POSITION,
            ScopeInterface::SCOPE_STORE
        );

        return $value !== '' ? $value : 'suffix';
    }

    private function isFrontend(): bool
    {
        try {
            return $this->appState->getAreaCode() === 'frontend';
        } catch (\Throwable) {
            return false;
        }
    }
}
