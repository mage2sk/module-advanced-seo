<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Cron;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Feed\GoogleMerchantFeedBuilder;
use Panth\AdvancedSEO\Model\Feed\ProfileBasedFeedBuilder;
use Psr\Log\LoggerInterface;

/**
 * Daily cron job to regenerate product feeds.
 *
 * First generates all active feed profiles with cron_enabled = 1,
 * then falls back to legacy Google Merchant feed for stores without profiles.
 */
class GenerateGoogleFeed
{
    public function __construct(
        private readonly GoogleMerchantFeedBuilder $feedBuilder,
        private readonly ProfileBasedFeedBuilder $profileFeedBuilder,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly DirectoryList $directoryList,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $generatedStoreIds = [];

        // Phase 1: Generate all active cron-enabled feed profiles
        $results = $this->profileFeedBuilder->generateAllActive(null, true);

        foreach ($results as $feedId => $result) {
            if (isset($result['error'])) {
                $this->logger->error(sprintf(
                    'Panth SEO Feed Cron: profile #%d failed: %s',
                    $feedId,
                    $result['error']
                ));
            } else {
                $this->logger->info(sprintf(
                    'Panth SEO Feed Cron: profile #%d generated -> %s (%d products, %.2fs)',
                    $feedId,
                    $result['file_path'] ?? '',
                    $result['product_count'] ?? 0,
                    $result['generation_time'] ?? 0
                ));

                // Track which stores were handled by profiles
                $profile = $this->profileFeedBuilder->loadProfile($feedId);
                if ($profile !== null) {
                    $generatedStoreIds[] = (int) $profile['store_id'];
                }
            }
        }

        // Phase 2: Legacy feed for stores not covered by profiles
        $mediaDir = $this->directoryList->getPath(DirectoryList::MEDIA);
        $feedDir = $mediaDir . '/panth_seo';

        foreach ($this->storeRepository->getList() as $store) {
            $storeId = (int) $store->getId();
            if ($storeId === 0) {
                continue;
            }

            // Skip if a profile-based feed was already generated for this store
            if (in_array($storeId, $generatedStoreIds, true)) {
                continue;
            }

            if (!$this->config->isEnabled($storeId) || !$this->config->isMerchantFeedEnabled($storeId)) {
                continue;
            }

            $filePath = $feedDir . '/google_feed_' . $store->getCode() . '.xml';

            try {
                $this->feedBuilder->buildToFile($storeId, $filePath);
                $this->logger->info(sprintf(
                    'Panth SEO: Legacy Google feed generated for store "%s" -> %s',
                    $store->getCode(),
                    $filePath
                ));
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Panth SEO: Legacy Google feed failed for store "%s": %s',
                    $store->getCode(),
                    $e->getMessage()
                ));
            }
        }
    }
}
