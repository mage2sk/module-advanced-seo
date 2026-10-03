<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Feed;

use Panth\AdvancedSEO\Model\Feed\FeedProfile;
use PHPUnit\Framework\TestCase;

class FeedProfileTest extends TestCase
{
    private function profile(array $data = []): FeedProfile
    {
        /** @var FeedProfile $profile */
        $profile = (new \ReflectionClass(FeedProfile::class))->newInstanceWithoutConstructor();
        $profile->setData($data);
        return $profile;
    }

    public function testDefaultsWhenEmpty(): void
    {
        $profile = $this->profile();

        $this->assertNull($profile->getFeedId());
        $this->assertSame('', $profile->getName());
        $this->assertSame('google_shopping', $profile->getFeedType());
        $this->assertSame(0, $profile->getStoreId());
        $this->assertSame('google_feed.xml', $profile->getFilename());
        $this->assertSame('xml', $profile->getOutputFormat());
        $this->assertFalse($profile->isActive());
        $this->assertFalse($profile->getIncludeOutOfStock());
        $this->assertFalse($profile->getIncludeDisabled());
        $this->assertFalse($profile->getIncludeNotVisible());
        $this->assertSame('', $profile->getCategoryFilter());
        $this->assertSame('', $profile->getAttributeSetFilter());
        $this->assertSame('US', $profile->getDeliveryCountry());
        $this->assertSame('USD', $profile->getCurrency());
        $this->assertFalse($profile->isCronEnabled());
        $this->assertSame('0 1 * * *', $profile->getCronSchedule());
        $this->assertNull($profile->getLastGeneratedAt());
        $this->assertNull($profile->getGenerationTime());
        $this->assertSame(0, $profile->getProductCount());
        $this->assertSame(0, $profile->getFileSize());
        $this->assertNull($profile->getFileUrl());
    }

    public function testStoredValuesAreCast(): void
    {
        $profile = $this->profile([
            'feed_id' => '4',
            'include_out_of_stock' => '1',
            'include_disabled' => 1,
            'include_not_visible' => '1',
            'category_filter' => '3,4',
            'attribute_set_filter' => '9',
            'delivery_country' => 'GB',
            'currency' => 'GBP',
            'cron_enabled' => '1',
            'cron_schedule' => '*/5 * * * *',
            'last_generated_at' => '2026-01-01 00:00:00',
            'generation_time' => '12',
            'product_count' => '100',
            'file_size' => '2048',
            'file_url' => 'https://shop.test/feed.xml',
        ]);

        $this->assertSame(4, $profile->getFeedId());
        $this->assertTrue($profile->getIncludeOutOfStock());
        $this->assertTrue($profile->getIncludeDisabled());
        $this->assertTrue($profile->getIncludeNotVisible());
        $this->assertSame('3,4', $profile->getCategoryFilter());
        $this->assertSame('9', $profile->getAttributeSetFilter());
        $this->assertSame('GB', $profile->getDeliveryCountry());
        $this->assertSame('GBP', $profile->getCurrency());
        $this->assertTrue($profile->isCronEnabled());
        $this->assertSame('*/5 * * * *', $profile->getCronSchedule());
        $this->assertSame('2026-01-01 00:00:00', $profile->getLastGeneratedAt());
        $this->assertSame(12, $profile->getGenerationTime());
        $this->assertSame(100, $profile->getProductCount());
        $this->assertSame(2048, $profile->getFileSize());
        $this->assertSame('https://shop.test/feed.xml', $profile->getFileUrl());
    }

    public function testSettersRoundTrip(): void
    {
        $profile = $this->profile();

        $this->assertSame($profile, $profile->setName('Main'));
        $profile->setFeedType('facebook')
            ->setStoreId(2)
            ->setFilename('fb.csv')
            ->setOutputFormat('csv')
            ->setIsActive(true);

        $this->assertSame('Main', $profile->getName());
        $this->assertSame('facebook', $profile->getFeedType());
        $this->assertSame(2, $profile->getStoreId());
        $this->assertSame('fb.csv', $profile->getFilename());
        $this->assertSame('csv', $profile->getOutputFormat());
        $this->assertTrue($profile->isActive());
        $this->assertSame(1, $profile->getData('is_active'));
    }
}
