<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Response;

use Magento\Framework\App\Area;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\State as AppState;
use Panth\AdvancedSEO\Api\RobotsPolicyInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Logger\Logger as SeoDebugLogger;
use Panth\AdvancedSEO\Model\Robots\MetaResolver as RobotsMetaResolver;
use Panth\AdvancedSEO\Service\NoindexPathMatcher;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Adds the X-Robots-Tag HTTP header on frontend responses.
 *
 * Reinforces the HTML <meta name="robots"> directive at the HTTP level.
 * For non-HTML assets (PDFs, images served through Magento controllers),
 * the header is emitted based on a URL pattern check so search engines
 * receive consistent robots directives regardless of content type.
 */
class XRobotsTagPlugin
{
    /** @var string[] URL extensions that should carry noindex by default */
    private const NOINDEX_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];

    /** @var int[] HTTP status codes that must never be indexed */
    private const NOINDEX_STATUS_CODES = [404, 410, 500, 503];

    public function __construct(
        private readonly AppState $appState,
        private readonly RobotsPolicyInterface $robotsPolicy,
        private readonly SeoConfig $config,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly ?RobotsMetaResolver $robotsMetaResolver = null,
        private readonly ?SeoDebugLogger $seoDebugLogger = null,
        private readonly ?NoindexPathMatcher $noindexPathMatcher = null
    ) {
    }

    /**
     * Emit a structured debug log when the admin debug toggle is on. Silent
     * otherwise so production never pays the I/O cost.
     *
     * @param array<string,mixed> $context
     */
    private function debug(string $message, array $context = []): void
    {
        if ($this->seoDebugLogger === null) {
            return;
        }
        if (!$this->config->isDebug()) {
            return;
        }
        $this->seoDebugLogger->debug($message, $context);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSendResponse(HttpResponse $subject): void
    {
        try {
            if (!$this->isFrontendArea()) {
                return;
            }

            $storeId = (int) $this->storeManager->getStore()->getId();

            if (!$this->config->isEnabled($storeId)) {
                return;
            }

            // Error/unavailable responses (404, 410, 5xx) must never be indexed,
            // regardless of any other configuration. The HTTP header takes
            // precedence over <meta name="robots"> per Google's documentation,
            // so we must enforce noindex here to prevent error pages from
            // leaking into search results.
            if (in_array((int) $subject->getStatusCode(), self::NOINDEX_STATUS_CODES, true)) {
                $subject->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
                $this->debug('panth_seo: xrobots.emitted', [
                    'uri' => (string) $this->request->getRequestUri(),
                    'reason' => 'status_code',
                    'status' => (int) $subject->getStatusCode(),
                    'value' => 'noindex, nofollow',
                ]);
                return;
            }

            // For non-HTML responses, check if the URL points to a document type
            // that should carry a noindex directive.
            $requestUri = (string) $this->request->getRequestUri();
            if ($this->isNoindexAssetUrl($requestUri)) {
                $subject->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
                $this->debug('panth_seo: xrobots.emitted', [
                    'uri' => $requestUri,
                    'reason' => 'asset_extension',
                    'value' => 'noindex, nofollow',
                ]);
                return;
            }

            // NOTE (coordination): this search-result branch was added by the
            // search-meta fix agent. It MUST run before the generic
            // NoindexPathMatcher block below, because that block would
            // emit `noindex, nofollow` for `/catalogsearch/*` (since that
            // path is in the default private-path list). Search result
            // pages should be `noindex, follow` instead so crawlers still
            // traverse the product links on the page and credit equity to
            // the destinations. The `follow` vs `nofollow` distinction is
            // the entire reason this branch exists separately from
            // NoindexPathMatcher.
            if ($this->isSearchResultPath($requestUri)
                && $this->config->isNoindexSearchResults($storeId)
            ) {
                $subject->setHeader('X-Robots-Tag', 'noindex, follow', true);
                $this->debug('panth_seo: xrobots.emitted', [
                    'uri' => $requestUri,
                    'reason' => 'search_result',
                    'store_id' => $storeId,
                    'value' => 'noindex, follow',
                ]);
                return;
            }

            // Private / customer-scoped paths (login, checkout, wishlist,
            // sales history, contact, captcha, search, account endpoints)
            // must never be indexed. We force noindex,nofollow here BEFORE
            // the generic HTML-response branch below would otherwise emit
            // `index, follow`. The X-Robots-Tag header takes precedence over
            // any HTML meta tag per Google's documentation, so this is the
            // authoritative enforcement point.
            if ($this->noindexPathMatcher !== null
                && $this->noindexPathMatcher->isNoindexPath($requestUri, $storeId)
            ) {
                $subject->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
                $this->debug('panth_seo: xrobots.emitted', [
                    'uri' => $requestUri,
                    'reason' => 'private_path',
                    'store_id' => $storeId,
                    'value' => 'noindex, nofollow',
                ]);
                return;
            }

            // For HTML responses, always set X-Robots-Tag header
            $robots = $this->robotsPolicy->getHeaderRobots('', 0, $storeId);
            if ($this->robotsMetaResolver !== null) {
                $robots = $this->robotsMetaResolver->appendAdvancedDirectives($robots, $storeId);
            }
            if ($robots === '') {
                $robots = 'index, follow';
            }
            $subject->setHeader('X-Robots-Tag', $robots, true);
            $this->debug('panth_seo: xrobots.emitted', [
                'uri' => $requestUri,
                'reason' => 'html_response',
                'store_id' => $storeId,
                'value' => $robots,
            ]);
        } catch (\Throwable $e) {
            $this->logger->debug('Panth SEO XRobotsTagPlugin: ' . $e->getMessage());
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

    /**
     * Check if the request targets a document/file type that should be noindexed.
     */
    private function isNoindexAssetUrl(string $uri): bool
    {
        $path = strtolower(parse_url($uri, PHP_URL_PATH) ?? '');
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        return in_array($extension, self::NOINDEX_EXTENSIONS, true);
    }

    /**
     * Return true when the request URI points at a catalog search result
     * page (both classic and advanced search). Query string is ignored so
     * `/catalogsearch/result/?q=jacket` matches the same path as
     * `/catalogsearch/result/`.
     */
    private function isSearchResultPath(string $uri): bool
    {
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '');
        if ($path === '') {
            return false;
        }
        return str_starts_with($path, '/catalogsearch/');
    }
}
