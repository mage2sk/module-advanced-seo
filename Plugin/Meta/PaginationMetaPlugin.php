<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Meta;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\View\Page\Config as PageConfig;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Plugin on Magento\Framework\View\Page\Config.
 *
 * When the current request is paginated (`?p=N`, N > 1) and the admin
 * configuration `panth_seo/meta/pagination_position` is not "none",
 * appends or prepends a page-number indicator to the meta title and
 * enriches the meta description with "Page N" information.
 *
 * Only fires on the frontend area.
 */
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

    /**
     * After-plugin on PageConfig::getTitle().
     *
     * Decorates the Title value object so that subsequent calls to
     * Title::get() already carry the pagination indicator. We modify
     * the underlying value via Title::set() and return the same object.
     *
     * @param PageConfig                              $subject
     * @param \Magento\Framework\View\Page\Title $result
     *
     * @return \Magento\Framework\View\Page\Title
     */
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

        // Idempotency guard: if the label is already present in the title,
        // don't splice it in a second time. This matters because `getTitle()`
        // is invoked multiple times per request (HeadPlugin::beforePublicBuild
        // calls `->set()` which triggers another afterGet), and without this
        // guard the plugin produced "Tops | Page 2 | Page 2".
        if ($labelKey !== '' && str_contains((string) $currentTitle, $labelKey)) {
            return $result;
        }

        $newTitle = $position === self::POSITION_PREFIX
            ? trim($label . ' ' . $currentTitle)
            : trim($currentTitle . ' ' . $label);

        $result->set($newTitle);

        // Also enrich meta description with page indicator
        $description = $subject->getDescription();
        if ($description !== '' && $description !== null) {
            $pageIndicator = sprintf(' - Page %d', $page);
            $subject->setDescription(rtrim($description, '.') . $pageIndicator);
        }

        return $result;
    }

    /**
     * Determine whether this plugin should execute.
     */
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
        // Prefer the helper so we share the same default fallback with the
        // rest of the module and allow per-store overrides to be honoured
        // without duplicating XML path constants.
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
