<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Feed;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Backend\App\Action\Context;

class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::manage';
    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $data = (array) $this->getRequest()->getPostValue();
        $resultRedirect = $this->resultRedirectFactory->create();
        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $id = (int) ($data['feed_id'] ?? 0);

        // Handle multiselect values (arrays to comma-separated strings)
        $categoryFilter = $data['category_filter'] ?? '';
        if (is_array($categoryFilter)) {
            $categoryFilter = implode(',', $categoryFilter);
        }
        $attrSetFilter = $data['attribute_set_filter'] ?? '';
        if (is_array($attrSetFilter)) {
            $attrSetFilter = implode(',', $attrSetFilter);
        }

        // Encrypt password if provided
        $deliveryPassword = (string) ($data['delivery_password'] ?? '');
        if ($deliveryPassword !== '') {
            $deliveryPassword = $this->encryptor->encrypt($deliveryPassword);
        }

        $row = [
            'name'                => mb_substr((string) ($data['name'] ?? ''), 0, 255),
            'feed_type'           => (string) ($data['feed_type'] ?? 'google_shopping'),
            'store_id'            => (int) ($data['store_id'] ?? 1),
            'filename'            => mb_substr((string) ($data['filename'] ?? 'google_feed.xml'), 0, 255),
            'output_format'       => (string) ($data['output_format'] ?? 'xml'),
            'is_active'           => (int) ($data['is_active'] ?? 1),
            'field_mapping'       => (string) ($data['field_mapping'] ?? ''),
            'conditions_serialized' => (string) ($data['conditions_serialized'] ?? ''),
            'include_out_of_stock' => (int) ($data['include_out_of_stock'] ?? 0),
            'include_disabled'    => (int) ($data['include_disabled'] ?? 0),
            'include_not_visible' => (int) ($data['include_not_visible'] ?? 0),
            'category_filter'     => (string) $categoryFilter,
            'attribute_set_filter' => (string) $attrSetFilter,
            'delivery_country'    => mb_substr((string) ($data['delivery_country'] ?? 'US'), 0, 2),
            'currency'            => mb_substr((string) ($data['currency'] ?? 'USD'), 0, 3),
            'cron_enabled'        => (int) ($data['cron_enabled'] ?? 0),
            'cron_schedule'       => (string) ($data['cron_schedule'] ?? '0 1 * * *'),
            'delivery_enabled'    => (int) ($data['delivery_enabled'] ?? 0),
            'delivery_type'       => (string) ($data['delivery_type'] ?? 'ftp'),
            'delivery_host'       => mb_substr((string) ($data['delivery_host'] ?? ''), 0, 255),
            'delivery_user'       => mb_substr((string) ($data['delivery_user'] ?? ''), 0, 255),
            'delivery_password'   => $deliveryPassword,
            'delivery_path'       => mb_substr((string) ($data['delivery_path'] ?? ''), 0, 255),
            'delivery_passive_mode' => (int) ($data['delivery_passive_mode'] ?? 1),
            'utm_source'          => mb_substr((string) ($data['utm_source'] ?? ''), 0, 255),
            'utm_medium'          => mb_substr((string) ($data['utm_medium'] ?? ''), 0, 255),
            'utm_campaign'        => mb_substr((string) ($data['utm_campaign'] ?? ''), 0, 255),
            'compress'            => mb_substr((string) ($data['compress'] ?? ''), 0, 10),
            'updated_at'          => $this->dateTime->gmtDate(),
        ];

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_feed_profile');
            if ($id > 0) {
                $connection->update($table, $row, ['feed_id = ?' => $id]);
            } else {
                $row['created_at'] = $this->dateTime->gmtDate();
                $connection->insert($table, $row);
                $id = (int) $connection->lastInsertId($table);
            }
            $this->messageManager->addSuccessMessage(__('Feed profile saved.'));
            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        return $resultRedirect->setPath('*/*/');
    }
}
