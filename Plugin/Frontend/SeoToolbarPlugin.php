<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Frontend;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Module\Dir as ModuleDir;
use Magento\Framework\View\Result\Page;
use Panth\AdvancedSEO\ViewModel\SeoToolbar;

class SeoToolbarPlugin
{
    public function __construct(
        private readonly SeoToolbar $toolbar,
        private readonly AppState $appState,
        private readonly ModuleDir $moduleDir
    ) {
    }

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

                if (method_exists($httpResponse, 'setNoCacheHeaders')) {
                    $httpResponse->setNoCacheHeaders();
                }
            }
        } catch (\Throwable) {
        }

        return $result;
    }

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
            include $templatePath;
        } catch (\Throwable) {
            ob_end_clean();
            return '';
        }
        $out = ob_get_clean();
        return $out === false ? '' : $out;
    }

    public function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function escJson(string $json): string
    {
        $safe = str_replace(['</script>', '</SCRIPT>'], ['<\/script>', '<\/SCRIPT>'], $json);
        return htmlspecialchars($safe, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

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
