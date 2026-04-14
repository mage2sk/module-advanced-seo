<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Robots;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\RobotsPolicyInterface;

/**
 * Serves a dynamic robots.txt for the current store from PolicyResolver.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly RobotsPolicyInterface $robotsPolicy,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function execute(): ResponseInterface|ResultInterface
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $body = $this->robotsPolicy->getRobotsTxt($storeId);
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setHeader('X-Robots-Tag', 'noindex', true);
        $result->setContents($body);
        return $result;
    }
}
