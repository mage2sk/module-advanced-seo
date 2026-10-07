<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Feed;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Model\Feed\FtpDelivery;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Encryption\EncryptorInterface;

class TestConnection extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::feeds';
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly FtpDelivery $ftpDelivery,
        private readonly EncryptorInterface $encryptor,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $type = (string) $this->getRequest()->getParam('delivery_type', 'ftp');
            $host = trim((string) $this->getRequest()->getParam('delivery_host', ''));
            $user = trim((string) $this->getRequest()->getParam('delivery_user', ''));
            $password = (string) $this->getRequest()->getParam('delivery_password', '');
            $path = trim((string) $this->getRequest()->getParam('delivery_path', '/'));
            $feedId = (int) $this->getRequest()->getParam('feed_id', 0);

            if ($host === '' || $user === '') {
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('Host and User are required.'),
                ]);
            }

            if ($password === '' && $feedId > 0) {
                $password = $this->getSavedPassword($feedId);
            }

            $message = $this->ftpDelivery->testConnection($type, $host, $user, $password, $path);

            return $result->setData([
                'success' => true,
                'message' => (string) __($message),
            ]);
        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'message' => (string) __('Connection failed: %1', $e->getMessage()),
            ]);
        }
    }

    private function getSavedPassword(int $feedId): string
    {
        $connection = $this->resource->getConnection();
        $stored = (string) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('panth_seo_feed_profile'), ['delivery_password'])
                ->where('feed_id = ?', $feedId)
        );
        if ($stored === '') {
            return '';
        }
        $decrypted = $this->encryptor->decrypt($stored);

        return $decrypted !== '' ? $decrypted : $stored;
    }
}
