<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Feed;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Backend\App\Action\Context;
use Panth\AdvancedSEO\Model\Feed\ProfileBasedFeedBuilder;

/**
 * Admin controller: generates a feed from a specific feed profile.
 *
 * Accepts `id` (feed_id) param, generates the feed, and redirects back with stats.
 */
class Generate extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::manage';

    public function __construct(
        Context $context,
        private readonly ProfileBasedFeedBuilder $feedBuilder
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $feedId = (int) $this->getRequest()->getParam('id');
        $resultRedirect = $this->resultRedirectFactory->create();

        if ($feedId <= 0) {
            $this->messageManager->addErrorMessage(__('Invalid feed profile ID.'));
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $stats = $this->feedBuilder->generateById($feedId);

            $fileSize = $this->formatFileSize($stats['file_size'] ?? 0);

            $this->messageManager->addSuccessMessage(__(
                'Feed generated successfully: %1 products exported (%2) in %3 seconds.',
                $stats['product_count'] ?? 0,
                $fileSize,
                $stats['generation_time'] ?? 0
            ));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Error generating feed: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/');
    }

    /**
     * Format byte count for human-readable display.
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1048576, 1) . ' MB';
    }
}
