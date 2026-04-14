<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Feed;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Model\Feed\FtpDelivery;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * AJAX controller that tests FTP/SFTP connection.
 */
class TestConnection extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::manage';
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly FtpDelivery $ftpDelivery,
        private readonly EncryptorInterface $encryptor
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

            if ($host === '' || $user === '') {
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('Host and User are required.'),
                ]);
            }

            // Try to decrypt; if it fails, use as plain text
            $decrypted = $this->encryptor->decrypt($password);
            if ($decrypted !== '') {
                $password = $decrypted;
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
}
