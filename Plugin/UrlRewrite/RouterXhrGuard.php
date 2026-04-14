<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\UrlRewrite;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\UrlRewrite\Controller\Router;
use Panth\AdvancedSEO\Service\RedirectGuard;

/**
 * Block Magento core's UrlRewrite router from issuing a 301 on XHR / non-GET
 * requests. The redirect_type=301 rows in url_rewrite are a duplicate-content
 * mitigation for human navigation; firing them on AJAX/fetch causes browsers
 * to convert POST -> GET and breaks every JS-driven endpoint.
 *
 * Returning null from match() means "no match, fall through to other routers"
 * — the request then hits whatever controller naturally serves the URL.
 */
class RouterXhrGuard
{
    public function __construct(
        private readonly RedirectGuard $redirectGuard
    ) {
    }

    public function aroundMatch(
        Router $subject,
        callable $proceed,
        RequestInterface $request
    ): ?ActionInterface {
        if (!$this->redirectGuard->isSafeToRedirect($request)) {
            return null;
        }
        return $proceed($request);
    }
}
