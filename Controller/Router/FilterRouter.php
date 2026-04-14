<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\FilterUrl\UrlParser;

/**
 * Custom router that intercepts SEO-friendly filter URLs and translates them
 * into standard Magento category/view requests with filter parameters.
 *
 * This router MUST be registered to run AFTER the standard router but BEFORE
 * the default (CMS/noRoute) router. Registration order is controlled by the
 * sortOrder in di.xml (handled by the shared config agent).
 */
class FilterRouter implements RouterInterface
{
    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly UrlParser $urlParser,
        private readonly Config $config
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        if (!$this->config->isEnabled() || !$this->config->isFilterUrlEnabled()) {
            return null;
        }

        $pathInfo = trim((string) $request->getPathInfo(), '/');

        // Bail out quickly for empty paths, admin, or API routes.
        if ($pathInfo === '' || str_starts_with($pathInfo, 'admin') || str_starts_with($pathInfo, 'rest/')) {
            return null;
        }

        $result = $this->urlParser->parse($pathInfo);
        if ($result === null) {
            return null;
        }

        $categoryId = $result['category_id'];
        $filters    = $result['filters'];

        // Set Magento request to route to catalog/category/view.
        $request->setModuleName('catalog');
        $request->setControllerName('category');
        $request->setActionName('view');
        $request->setParam('id', $categoryId);

        // Set each filter as a request parameter so the layered navigation picks them up.
        foreach ($filters as $attrCode => $optionId) {
            $request->setParam($attrCode, (string) $optionId);
        }

        // Mark the request as dispatched to this router so other routers don't interfere.
        $request->setAlias(
            \Magento\Framework\Url::REWRITE_REQUEST_PATH_ALIAS,
            $pathInfo
        );

        return $this->actionFactory->create(
            \Magento\Framework\App\Action\Forward::class
        );
    }
}
