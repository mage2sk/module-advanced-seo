<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Response;

use Magento\Framework\App\Area;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\State as AppState;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class CanonicalHeaderPlugin
{
    public function __construct(
        private readonly AppState $appState,
        private readonly RequestInterface $request,
        private readonly SeoConfig $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function beforeSendResponse(HttpResponse $subject): void
    {
        try {
            if (!$this->isFrontendArea() || $this->isAjaxRequest() || !$this->hasHtmlContentType($subject)) {
                return;
            }

            $head = $this->getRenderedHead((string) $subject->getContent());
            if ($head === '') {
                return;
            }

            $storeId = (int) $this->storeManager->getStore()->getId();

            if (!$this->config->isEnabled($storeId) || !$this->config->isCanonicalEnabled($storeId)) {
                return;
            }

            $canonicalUrl = $this->extractCanonicalUrl($head);
            if ($canonicalUrl === '') {
                return;
            }

            $this->addLinkHeader($subject, '<' . $canonicalUrl . '>; rel="canonical"');
        } catch (\Throwable $e) {
            $this->logger->debug('Panth SEO CanonicalHeaderPlugin: ' . $e->getMessage());
        }
    }

    private function isFrontendArea(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_FRONTEND;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isAjaxRequest(): bool
    {
        if (method_exists($this->request, 'isAjax') && $this->request->isAjax()) {
            return true;
        }

        return method_exists($this->request, 'isXmlHttpRequest') && $this->request->isXmlHttpRequest();
    }

    private function hasHtmlContentType(HttpResponse $response): bool
    {
        $header = $response->getHeader('Content-Type');
        if (!$header) {
            return true;
        }

        return stripos($this->headerValue($header), 'text/html') !== false;
    }

    private function getRenderedHead(string $content): string
    {
        $headEnd = stripos($content, '</head>');
        if ($headEnd === false || stripos($content, '<html') === false) {
            return '';
        }

        return substr($content, 0, $headEnd);
    }

    private function extractCanonicalUrl(string $head): string
    {
        if (!preg_match_all('/<link\b[^>]*>/i', $head, $matches)) {
            return '';
        }

        foreach ($matches[0] as $tag) {
            if (!preg_match('/\brel\s*=\s*(["\'])\s*canonical\s*\1/i', $tag)) {
                continue;
            }
            if (!preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/is', $tag, $href)) {
                continue;
            }

            $url = trim(html_entity_decode($href[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($url === '' || preg_match('/[\x00-\x20<>"\x7f]/', $url) || !preg_match('#^https?://#i', $url)) {
                return '';
            }

            return $url;
        }

        return '';
    }

    private function addLinkHeader(HttpResponse $response, string $value): void
    {
        $existing = $response->getHeader('Link');
        $current = $existing ? trim($this->headerValue($existing)) : '';

        if ($current !== '') {
            if (preg_match('/rel\s*=\s*"?canonical"?/i', $current)) {
                return;
            }
            $value = $current . ', ' . $value;
        }

        $response->setHeader('Link', $value, true);
    }

    private function headerValue($header): string
    {
        if (is_iterable($header)) {
            $values = [];
            foreach ($header as $item) {
                $values[] = $item->getFieldValue();
            }

            return implode(', ', $values);
        }

        return (string) $header->getFieldValue();
    }
}
