<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Feed;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;

class AddDefaultFields extends AbstractAction implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::manage';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $feedId = (int) $this->getRequest()->getParam('feed_id');
        $resultRedirect = $this->resultRedirectFactory->create();

        if ($feedId <= 0) {
            $this->messageManager->addErrorMessage(__('Invalid feed profile ID.'));
            return $resultRedirect->setPath('*/*/index');
        }

        $defaults = [
            ['g:id',                       'attribute', 'sku',                    null,   10, 1],
            ['g:title',                    'attribute', 'name',                   null,   20, 1],
            ['g:description',              'attribute', 'description',            null,   30, 1],
            ['g:link',                     'template',  'product_url',            null,   40, 1],
            ['g:image_link',               'template',  'product_image_url',      null,   50, 1],
            ['g:additional_image_link',    'template',  'additional_images',      null,   60, 0],
            ['g:price',                    'template',  'product_price',          null,   70, 1],
            ['g:sale_price',               'template',  'product_special_price',  null,   80, 0],
            ['g:availability',             'template',  'stock_status',           null,   90, 1],
            ['g:condition',                'static',    'new',                    null,  100, 0],
            ['g:brand',                    'attribute', 'manufacturer',           null,  110, 0],
            ['g:gtin',                     'attribute', 'gtin',                   null,  120, 0],
            ['g:mpn',                      'attribute', 'sku',                    null,  130, 0],
            ['g:product_type',             'template',  'category_path',          null,  140, 0],
            ['g:identifier_exists',        'static',    'false',                  null,  150, 0],
            ['g:item_group_id',            'template',  'parent_sku',             null,  160, 0],
            ['g:color',                    'attribute', 'color',                  null,  170, 0],
            ['g:size',                     'attribute', 'size',                   null,  180, 0],
            ['g:material',                 'attribute', 'material',              null,  190, 0],
            ['g:shipping_weight',          'template',  'product_weight',         null,  200, 0],
        ];

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_feed_field');

            // Get existing field names for this feed to avoid duplicates
            $existingFields = $connection->fetchCol(
                $connection->select()
                    ->from($table, ['feed_field'])
                    ->where('feed_id = ?', $feedId)
            );

            $inserted = 0;
            $skipped = 0;
            foreach ($defaults as [$feedField, $sourceType, $sourceValue, $defaultValue, $sortOrder, $isRequired]) {
                if (in_array($feedField, $existingFields, true)) {
                    $skipped++;
                    continue;
                }
                $connection->insert($table, [
                    'feed_id'       => $feedId,
                    'feed_field'    => $feedField,
                    'source_type'   => $sourceType,
                    'source_value'  => $sourceValue,
                    'default_value' => $defaultValue,
                    'sort_order'    => $sortOrder,
                    'is_required'   => $isRequired,
                ]);
                $inserted++;
            }

            if ($inserted > 0) {
                $this->messageManager->addSuccessMessage(
                    __('Added %1 Google Shopping default field mappings.', $inserted)
                );
            }
            if ($skipped > 0) {
                $this->messageManager->addNoticeMessage(
                    __('Skipped %1 fields that already exist.', $skipped)
                );
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $resultRedirect->setPath('*/feed/fields', ['feed_id' => $feedId]);
    }
}
