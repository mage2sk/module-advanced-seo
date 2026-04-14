<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Feed;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedSEO\Model\ResourceModel\FeedProfile as FeedProfileResource;

class FeedProfile extends AbstractModel
{
    protected $_idFieldName = 'feed_id';
    protected $_eventPrefix = 'panth_seo_feed_profile';

    protected function _construct(): void
    {
        $this->_init(FeedProfileResource::class);
    }

    public function getFeedId(): ?int
    {
        $v = $this->getData('feed_id');
        return $v === null ? null : (int) $v;
    }

    public function getName(): string
    {
        return (string) $this->getData('name');
    }

    public function setName(string $name): self
    {
        return $this->setData('name', $name);
    }

    public function getFeedType(): string
    {
        return (string) ($this->getData('feed_type') ?: 'google_shopping');
    }

    public function setFeedType(string $type): self
    {
        return $this->setData('feed_type', $type);
    }

    public function getStoreId(): int
    {
        return (int) $this->getData('store_id');
    }

    public function setStoreId(int $storeId): self
    {
        return $this->setData('store_id', $storeId);
    }

    public function getFilename(): string
    {
        return (string) ($this->getData('filename') ?: 'google_feed.xml');
    }

    public function setFilename(string $filename): self
    {
        return $this->setData('filename', $filename);
    }

    public function getOutputFormat(): string
    {
        return (string) ($this->getData('output_format') ?: 'xml');
    }

    public function setOutputFormat(string $format): self
    {
        return $this->setData('output_format', $format);
    }

    public function isActive(): bool
    {
        return (bool) $this->getData('is_active');
    }

    public function setIsActive(bool $flag): self
    {
        return $this->setData('is_active', (int) $flag);
    }

    public function getIncludeOutOfStock(): bool
    {
        return (bool) $this->getData('include_out_of_stock');
    }

    public function getIncludeDisabled(): bool
    {
        return (bool) $this->getData('include_disabled');
    }

    public function getIncludeNotVisible(): bool
    {
        return (bool) $this->getData('include_not_visible');
    }

    public function getCategoryFilter(): string
    {
        return (string) $this->getData('category_filter');
    }

    public function getAttributeSetFilter(): string
    {
        return (string) $this->getData('attribute_set_filter');
    }

    public function getDeliveryCountry(): string
    {
        return (string) ($this->getData('delivery_country') ?: 'US');
    }

    public function getCurrency(): string
    {
        return (string) ($this->getData('currency') ?: 'USD');
    }

    public function isCronEnabled(): bool
    {
        return (bool) $this->getData('cron_enabled');
    }

    public function getCronSchedule(): string
    {
        return (string) ($this->getData('cron_schedule') ?: '0 1 * * *');
    }

    public function getLastGeneratedAt(): ?string
    {
        $v = $this->getData('last_generated_at');
        return $v === null ? null : (string) $v;
    }

    public function getGenerationTime(): ?int
    {
        $v = $this->getData('generation_time');
        return $v === null ? null : (int) $v;
    }

    public function getProductCount(): int
    {
        return (int) $this->getData('product_count');
    }

    public function getFileSize(): int
    {
        return (int) $this->getData('file_size');
    }

    public function getFileUrl(): ?string
    {
        $v = $this->getData('file_url');
        return $v === null ? null : (string) $v;
    }
}
