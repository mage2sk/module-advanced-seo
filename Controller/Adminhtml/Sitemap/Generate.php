<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Sitemap;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Model\Sitemap\Builder;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Backend\App\Action\Context;

class Generate extends AbstractAction implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::sitemap';

    public function __construct(
        Context $context,
        private readonly Builder $builder
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $profileId = (int) $this->getRequest()->getParam('id', 0);
        if ($profileId === 0) {
            $profileId = (int) $this->getRequest()->getParam('profile_id', 0);
        }

        try {
            if ($profileId > 0) {
                // Profile-based generation
                $profile = $this->builder->loadProfile($profileId);
                if ($profile === null) {
                    $this->messageManager->addErrorMessage(
                        __('Sitemap profile with ID %1 was not found.', $profileId)
                    );
                    return $resultRedirect->setPath('*/*/');
                }

                $stats = $this->builder->buildFromProfile($profile);
                $this->builder->updateProfileStats($profileId, $stats);

                $this->messageManager->addSuccessMessage(
                    __(
                        'Sitemap generated for profile "%1": %2 URLs in %3 files (%4 seconds).',
                        $profile['name'] ?? 'unnamed',
                        $stats['url_count'],
                        $stats['file_count'],
                        number_format($stats['generation_time'], 2)
                    )
                );
            } else {
                // Legacy: generate for all active profiles
                $profiles = $this->builder->loadActiveProfiles();
                if (!empty($profiles)) {
                    $totalUrls  = 0;
                    $totalFiles = 0;
                    $totalTime  = 0.0;

                    foreach ($profiles as $profile) {
                        $stats = $this->builder->buildFromProfile($profile);
                        $pid = (int) ($profile['profile_id'] ?? 0);
                        if ($pid > 0) {
                            $this->builder->updateProfileStats($pid, $stats);
                        }
                        $totalUrls  += $stats['url_count'];
                        $totalFiles += $stats['file_count'];
                        $totalTime  += $stats['generation_time'];
                    }

                    $this->messageManager->addSuccessMessage(
                        __(
                            'Sitemap generated for %1 profile(s): %2 URLs in %3 files (%4 seconds total).',
                            count($profiles),
                            $totalUrls,
                            $totalFiles,
                            number_format($totalTime, 2)
                        )
                    );
                } else {
                    $this->messageManager->addSuccessMessage(
                        __(
                            'No active sitemap profiles found. Please create a profile first, or use "bin/magento panth:seo:sitemap --store=<id>" for legacy generation.'
                        )
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Error generating sitemap: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/');
    }
}
