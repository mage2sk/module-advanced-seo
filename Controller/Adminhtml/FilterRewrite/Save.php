<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\FilterRewrite;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResourceConnection;

/**
 * Save controller for inline editing of filter rewrite records.
 */
class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::filter_rewrites';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultJson = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_JSON);
        $error      = false;
        $messages   = [];

        $items = $this->getRequest()->getParam('items', []);
        if (!is_array($items) || $items === []) {
            $error      = true;
            $messages[] = __('Please correct the data sent.');
            $resultJson->setData(['messages' => $messages, 'error' => $error]);
            return $resultJson;
        }

        $connection = $this->resource->getConnection();
        $tableName  = $this->resource->getTableName('panth_seo_filter_rewrite');

        foreach ($items as $id => $data) {
            try {
                $updateData = [];
                foreach (['rewrite_slug', 'is_active', 'option_label', 'attribute_code', 'store_id'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $updateData[$field] = $data[$field];
                    }
                }
                if ($updateData !== []) {
                    $connection->update(
                        $tableName,
                        $updateData,
                        ['rewrite_id = ?' => (int) $id]
                    );
                }
            } catch (\Throwable $e) {
                $error      = true;
                $messages[] = '[ID: ' . (int) $id . '] Could not save record.';
            }
        }

        $resultJson->setData([
            'messages' => $messages ?: [__('Record(s) saved.')],
            'error'    => $error,
        ]);

        return $resultJson;
    }
}
