<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Llms;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Model\LlmsTxt\Builder;

/**
 * Serves /llms.txt from the Builder.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly Builder $builder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function execute(): ResponseInterface|ResultInterface
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);

        if (!$this->builder->isEnabled($storeId)) {
            $result->setHttpResponseCode(404);
            $result->setContents("# llms.txt\n\nllms.txt is not enabled for this store.\n");
            return $result;
        }

        $result->setContents($this->builder->build($storeId));
        return $result;
    }
}
