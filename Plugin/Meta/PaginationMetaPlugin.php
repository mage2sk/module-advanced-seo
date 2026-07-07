<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Meta;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\View\Page\Config as PageConfig;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

class PaginationMetaPlugin
{
    private const XML_PAGINATION_POSITION = 'panth_seo/meta/pagination_position';
    private const XML_PAGINATION_FORMAT   = 'panth_seo/meta/pagination_format';
    private const DEFAULT_FORMAT          = '| Page %p';
    private const POSITION_NONE           = 'none';
    private const POSITION_PREFIX         = 'prefix';
    private const POSITION_SUFFIX         = 'suffix';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly State $appState,
        private readonly SeoConfig $seoConfig
    ) {
    }

    public function afterGetTitle(PageConfig $subject, \Magento\Framework\View\Page\Title $result): \Magento\Framework\View\Page\Title
    {
        if (!$this->shouldProcess()) {
            return $result;
        }

        $page     = $this->getCurrentPage();
        $position = $this->getPosition();
        $format   = $this->getFormat();
        $label    = str_replace('%p', (string) $page, $format);
        $labelKey = trim($label);

        $currentTitle = $result->getShort();
        if ($currentTitle === '' || $currentTitle === null) {
            return $result;
        }

        if ($labelKey !== '' && str_contains((string) $currentTitle, $labelKey)) {
            return $result;
        }

        $newTitle = $position === self::POSITION_PREFIX
            ? trim($label . ' ' . $currentTitle)
            : trim($currentTitle . ' ' . $label);

        $result->set($newTitle);

        $description = $subject->getDescription();
        if ($description !== '' && $description !== null) {
            $pageIndicator = sprintf(' - Page %d', $page);
            $subject->setDescription(rtrim($description, '.') . $pageIndicator);
        }

        return $result;
    }

    private function shouldProcess(): bool
    {
        if (!$this->seoConfig->isEnabled()) {
            return false;
        }

        if (!$this->isFrontend()) {
            return false;
        }

        if ($this->getPosition() === self::POSITION_NONE) {
            return false;
        }

        return $this->getCurrentPage() > 1;
    }

    private function getCurrentPage(): int
    {
        return max(1, (int) $this->request->getParam('p', 1));
    }

    private function getPosition(): string
    {
        $value = $this->seoConfig->getPaginationPosition();
        return $value !== '' ? $value : self::POSITION_SUFFIX;
    }

    private function getFormat(): string
    {
        $value = $this->seoConfig->getPaginationFormat();
        return $value !== '' ? $value : self::DEFAULT_FORMAT;
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
