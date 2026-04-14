<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Llms;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Model\LlmsTxt\FullBuilder;

/**
 * Serves /seo/llms/full as `llms-full.txt` (expanded LLM content).
 */
class Full implements HttpGetActionInterface
{
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly FullBuilder $fullBuilder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function execute(): ResponseInterface|ResultInterface
    {
        $storeId = (int) $this->storeManager->getStore()->getId();

        if (!$this->fullBuilder->isEnabled($storeId)) {
            $result = $this->rawFactory->create();
            $result->setHttpResponseCode(404);
            $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
            $result->setContents("# llms-full.txt\n\nExpanded LLM content is not enabled for this store.\n");
            return $result;
        }

        $body = $this->fullBuilder->build($storeId);
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setHeader('X-Robots-Tag', 'noindex', true);
        $result->setContents($body);
        return $result;
    }
}
