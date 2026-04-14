<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedSEO\Model\ResourceModel\SitemapProfile as SitemapProfileResource;

class SitemapProfile extends AbstractModel
{
    protected $_idFieldName = 'profile_id';

    protected function _construct(): void
    {
        $this->_init(SitemapProfileResource::class);
    }

    public function getProfileId(): ?int
    {
        $value = $this->getData('profile_id');
        return $value === null ? null : (int) $value;
    }

    public function getName(): string
    {
        return (string) $this->getData('name');
    }

    public function getStoreId(): int
    {
        return (int) $this->getData('store_id');
    }

    public function getEntityTypes(): string
    {
        return (string) $this->getData('entity_types');
    }

    public function isActive(): bool
    {
        return (bool) $this->getData('is_active');
    }

    public function isCronEnabled(): bool
    {
        return (bool) $this->getData('cron_enabled');
    }

    public function getCronSchedule(): string
    {
        return (string) ($this->getData('cron_schedule') ?? '0 2 * * *');
    }

    public function getOutputPath(): string
    {
        return (string) ($this->getData('output_path') ?? 'sitemap/panth/{store_code}/');
    }

    public function getMaxUrlsPerFile(): int
    {
        return (int) ($this->getData('max_urls_per_file') ?: 50000);
    }

    public function getUrlCount(): int
    {
        return (int) ($this->getData('url_count') ?? 0);
    }

    public function getFileCount(): int
    {
        return (int) ($this->getData('file_count') ?? 0);
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

    public function getCustomLinks(): ?string
    {
        $v = $this->getData('custom_links');
        return $v === null ? null : (string) $v;
    }
}
