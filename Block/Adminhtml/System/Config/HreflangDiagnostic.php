<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Model\Hreflang\Diagnostic;

/**
 * System config field renderer that displays hreflang diagnostic results.
 *
 * Shows green checkmarks for passing checks and red X marks for issues.
 * Designed to be used as a frontend_model in system.xml.
 */
class HreflangDiagnostic extends Field
{
    private Diagnostic $diagnostic;
    private StoreManagerInterface $storeManager;

    public function __construct(
        Context $context,
        Diagnostic $diagnostic,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->diagnostic = $diagnostic;
        $this->storeManager = $storeManager;
        parent::__construct($context, $data);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $storeId = $this->resolveStoreId();
        $issues = $this->diagnostic->runDiagnostics($storeId);

        return $this->renderDiagnosticOutput($issues);
    }

    /**
     * Remove the scope label to use the full row width.
     */
    protected function _renderScopeLabel(AbstractElement $element): string
    {
        return '';
    }

    /**
     * Determine the current store scope from the request.
     */
    private function resolveStoreId(): int
    {
        $storeCode = (string) $this->getRequest()->getParam('store', '');
        if ($storeCode !== '') {
            try {
                return (int) $this->storeManager->getStore($storeCode)->getId();
            } catch (\Throwable) {
                // Fall through to default.
            }
        }

        return (int) $this->storeManager->getDefaultStoreView()?->getId();
    }

    /**
     * Render diagnostic results as styled HTML.
     *
     * @param  array<int, array{type: string, severity: string, message: string}> $issues
     */
    private function renderDiagnosticOutput(array $issues): string
    {
        $checks = [
            'x_default'    => 'All groups have an x-default member',
            'orphan_group' => 'All groups have at least 2 members',
            'locale'       => 'All stores have a locale configured',
            'conflict'     => 'No conflicting locales within groups',
            'config'       => 'All group member stores have hreflang enabled',
        ];

        // Determine which check types have issues.
        $failedTypes = [];
        foreach ($issues as $issue) {
            $failedTypes[$issue['type']] = true;
        }

        $html = '<div style="padding:10px 0">';

        // Render each check as pass/fail.
        foreach ($checks as $type => $label) {
            if (isset($failedTypes[$type])) {
                $html .= $this->renderFail($label);
            } else {
                $html .= $this->renderPass($label);
            }
        }

        // Render detailed issue messages.
        if ($issues !== []) {
            $html .= '<div style="margin-top:12px;padding:10px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px">';
            $html .= '<strong style="display:block;margin-bottom:6px">Issues Found:</strong>';
            $html .= '<ul style="margin:0;padding-left:20px">';
            foreach ($issues as $issue) {
                $severity = $issue['severity'] === 'error'
                    ? 'color:#dc3545;font-weight:600'
                    : 'color:#856404';
                $escapedMessage = $this->escapeHtml($issue['message']);
                $html .= sprintf(
                    '<li style="margin-bottom:4px;%s">%s</li>',
                    $severity,
                    $escapedMessage
                );
            }
            $html .= '</ul></div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a passing check line.
     */
    private function renderPass(string $label): string
    {
        $escaped = $this->escapeHtml($label);
        return sprintf(
            '<div style="margin-bottom:4px"><span style="color:#28a745;font-weight:bold;margin-right:6px">&#10003;</span>%s</div>',
            $escaped
        );
    }

    /**
     * Render a failing check line.
     */
    private function renderFail(string $label): string
    {
        $escaped = $this->escapeHtml($label);
        return sprintf(
            '<div style="margin-bottom:4px"><span style="color:#dc3545;font-weight:bold;margin-right:6px">&#10007;</span>%s</div>',
            $escaped
        );
    }
}
