<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData\Provider;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Registry;
use Magento\Review\Model\ResourceModel\Review\CollectionFactory as ReviewCollectionFactory;
use Magento\Review\Model\Review;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;

/**
 * Emits up to N top approved Review nodes attached to the current product.
 * Only active if Magento reviews are enabled at the store level.
 *
 * Defensive gate: when the request targets a Panth_Testimonials route and the
 * Panth_Testimonials module is enabled, this provider steps aside so that the
 * testimonials Schema block remains the sole owner of Review/AggregateRating
 * output on those pages.
 */
class ReviewProvider extends AbstractProvider
{
    private const MAX_REVIEWS = 5;

    public function __construct(
        Registry $registry,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        Config $config,
        private readonly ReviewCollectionFactory $reviewCollectionFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ModuleManager $moduleManager
    ) {
        parent::__construct($registry, $request, $storeManager, $config);
    }

    public function getCode(): string
    {
        return 'review';
    }

    public function isApplicable(): bool
    {
        // Defensive: never emit on Panth_Testimonials routes when that module
        // is active; the testimonials Schema block owns Review output there.
        if ($this->isTestimonialsRoute()) {
            return false;
        }
        if ($this->getCurrentProduct() === null) {
            return false;
        }
        return (bool) $this->scopeConfig->isSetFlag('catalog/review/active', ScopeInterface::SCOPE_STORE);
    }

    /**
     * True when the current frontend route belongs to Panth_Testimonials and
     * the module itself is enabled.
     */
    private function isTestimonialsRoute(): bool
    {
        if (!$this->moduleManager->isEnabled('Panth_Testimonials')) {
            return false;
        }
        try {
            $routeName = (string) $this->request->getRouteName();
        } catch (\Throwable) {
            return false;
        }
        return $routeName === 'testimonials';
    }

    public function getJsonLd(): array
    {
        $product = $this->getCurrentProduct();
        if ($product === null) {
            return [];
        }
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $collection = $this->reviewCollectionFactory->create()
                ->addStoreFilter($storeId)
                ->addStatusFilter(Review::STATUS_APPROVED)
                ->addEntityFilter('product', (int) $product->getId())
                ->setDateOrder()
                ->addRateVotes();
            $collection->setPageSize(self::MAX_REVIEWS);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($collection as $review) {
            $title = trim((string) $review->getTitle());
            $detail = trim((string) $review->getDetail());
            $nickname = trim((string) $review->getNickname());
            if ($title === '' && $detail === '') {
                continue;
            }
            $ratingValue = $this->resolveReviewRating($review);
            $node = [
                '@type' => 'Review',
                'author' => [
                    '@type' => 'Person',
                    'name' => $nickname !== '' ? $nickname : 'Customer',
                ],
                'datePublished' => (string) $review->getCreatedAt(),
                'reviewBody' => $detail !== '' ? $detail : $title,
                'name' => $title !== '' ? $title : mb_substr($detail, 0, 80),
                'itemReviewed' => ['@id' => (string) $product->getProductUrl() . '#product'],
                'reviewRating' => [
                    '@type' => 'Rating',
                    'ratingValue' => $ratingValue,
                    'bestRating' => '5',
                    'worstRating' => '1',
                ],
            ];
            $out[] = $node;
        }
        return $out;
    }

    /**
     * Resolve the average rating for a single review by averaging its vote percentages.
     *
     * Magento stores each vote as a percentage (0-100). We average all votes for the
     * review, then scale from 0-100 to 1-5. Falls back to '5' if no votes are available.
     */
    private function resolveReviewRating(Review $review): string
    {
        try {
            $votes = $review->getRatingVotes();
            if ($votes !== null && count($votes) > 0) {
                $sum = 0.0;
                $count = 0;
                foreach ($votes as $vote) {
                    $percent = (float) $vote->getPercent();
                    if ($percent > 0) {
                        $sum += $percent;
                        $count++;
                    }
                }
                if ($count > 0) {
                    $avgPercent = $sum / $count;
                    // Scale from 0-100 percentage to 1-5 star range
                    $starRating = round(($avgPercent / 100.0) * 5.0, 1);
                    $starRating = max(1.0, min(5.0, $starRating));
                    return number_format($starRating, 1, '.', '');
                }
            }
        } catch (\Throwable) {
            // fall through to default
        }

        return '5';
    }
}
