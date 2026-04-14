<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Frontend;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Module\Dir as ModuleDir;
use Magento\Framework\View\Result\Page;
use Panth\AdvancedSEO\ViewModel\SeoToolbar;

/**
 * Appends a self-contained SEO diagnostic toolbar before </body> on frontend
 * pages when enabled and the visitor IP is whitelisted.
 *
 * All HTML, CSS and JS are inline so the toolbar works identically on both
 * Hyva (Alpine-based) and Luma (RequireJS-based) themes without pulling in
 * any theme-specific module loader.
 *
 * The actual markup lives in
 *   view/frontend/templates/seo_toolbar.phtml
 * and is rendered via a scoped include with two variables injected:
 *   $data    -- associative payload from {@see SeoToolbar::getData()}
 *   $helper  -- reference to this plugin instance for its tiny escape/class
 *               helpers (the phtml avoids any Magento block glue).
 */
class SeoToolbarPlugin
{
    public function __construct(
        private readonly SeoToolbar $toolbar,
        private readonly AppState $appState,
        private readonly ModuleDir $moduleDir
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterRenderResult(
        Page $subject,
        Page $result,
        ResponseInterface $httpResponse
    ): Page {
        try {
            if ($this->appState->getAreaCode() !== 'frontend') {
                return $result;
            }

            if (!$this->toolbar->isAllowed()) {
                return $result;
            }

            $body = $httpResponse->getBody();
            if ($body === '' || !is_string($body)) {
                return $result;
            }

            $toolbarHtml = $this->renderTemplate();
            if ($toolbarHtml === '') {
                return $result;
            }

            $pos = strripos($body, '</body>');
            if ($pos !== false) {
                $body = substr($body, 0, $pos) . $toolbarHtml . substr($body, $pos);
                $httpResponse->setBody($body);

                // Prevent Full Page Cache from storing a response that
                // contains the toolbar. Otherwise a page cached while the
                // toolbar was enabled would continue to serve the toolbar
                // to visitors after an admin disables the feature.
                if (method_exists($httpResponse, 'setNoCacheHeaders')) {
                    $httpResponse->setNoCacheHeaders();
                }
            }
        } catch (\Throwable) {
            // Never break the page; silently fail.
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Template loader
    // ------------------------------------------------------------------

    private function renderTemplate(): string
    {
        try {
            $data = $this->toolbar->getData();
        } catch (\Throwable) {
            return '';
        }

        try {
            $templatePath = $this->moduleDir->getDir('Panth_AdvancedSEO', 'view')
                . '/frontend/templates/seo_toolbar.phtml';
        } catch (\Throwable) {
            return '';
        }

        if (!is_file($templatePath)) {
            return '';
        }

        $helper = $this;
        ob_start();
        try {
            include $templatePath; // phpcs:ignore Magento2.Security.IncludeFile
        } catch (\Throwable) {
            ob_end_clean();
            return '';
        }
        $out = ob_get_clean();
        return $out === false ? '' : $out;
    }

    // ------------------------------------------------------------------
    // Tiny helpers exposed to the phtml template via $helper
    // ------------------------------------------------------------------

    public function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Safely embed a JSON string inside an HTML <script> or <pre>. Escapes the
     * closing </script> sequence so a malicious payload cannot break out.
     */
    public function escJson(string $json): string
    {
        $safe = str_replace(['</script>', '</SCRIPT>'], ['<\/script>', '<\/SCRIPT>'], $json);
        return htmlspecialchars($safe, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Status class ("g"/"y"/"r") based on whether $length falls in the
     * inclusive [$min, $max] window. 0 is always red.
     */
    public function statusClass(int $length, int $min, int $max): string
    {
        if ($length === 0) {
            return 'r';
        }
        if ($length >= $min && $length <= $max) {
            return 'g';
        }
        return 'y';
    }
}
