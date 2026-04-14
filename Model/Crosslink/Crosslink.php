<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Crosslink;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedSEO\Model\ResourceModel\Crosslink as CrosslinkResource;

class Crosslink extends AbstractModel
{
    protected $_idFieldName = 'crosslink_id';
    protected $_eventPrefix = 'panth_seo_crosslink';

    protected function _construct(): void
    {
        $this->_init(CrosslinkResource::class);
    }

    public function getCrosslinkId(): ?int
    {
        $v = $this->getData('crosslink_id');
        return $v === null ? null : (int) $v;
    }

    public function getKeyword(): string
    {
        return (string) $this->getData('keyword');
    }

    public function setKeyword(string $keyword): self
    {
        return $this->setData('keyword', $keyword);
    }

    public function getUrl(): string
    {
        return (string) $this->getData('url');
    }

    public function setUrl(string $url): self
    {
        return $this->setData('url', $url);
    }

    public function getUrlTitle(): string
    {
        return (string) $this->getData('url_title');
    }

    public function setUrlTitle(string $title): self
    {
        return $this->setData('url_title', $title);
    }

    public function getMaxReplacements(): int
    {
        return (int) ($this->getData('max_replacements') ?: 1);
    }

    public function setMaxReplacements(int $max): self
    {
        return $this->setData('max_replacements', $max);
    }

    public function isNofollow(): bool
    {
        return (bool) $this->getData('nofollow');
    }

    public function setNofollow(bool $flag): self
    {
        return $this->setData('nofollow', $flag);
    }

    public function getPriority(): int
    {
        return (int) $this->getData('priority');
    }

    public function setPriority(int $priority): self
    {
        return $this->setData('priority', $priority);
    }

    public function isActive(): bool
    {
        return (bool) $this->getData('is_active');
    }

    public function setIsActive(bool $flag): self
    {
        return $this->setData('is_active', $flag);
    }

    public function isInProduct(): bool
    {
        return (bool) $this->getData('in_product');
    }

    public function setInProduct(bool $flag): self
    {
        return $this->setData('in_product', $flag);
    }

    public function isInCategory(): bool
    {
        return (bool) $this->getData('in_category');
    }

    public function setInCategory(bool $flag): self
    {
        return $this->setData('in_category', $flag);
    }

    public function isInCms(): bool
    {
        return (bool) $this->getData('in_cms');
    }

    public function setInCms(bool $flag): self
    {
        return $this->setData('in_cms', $flag);
    }

    public function getStoreId(): int
    {
        return (int) $this->getData('store_id');
    }

    public function setStoreId(int $storeId): self
    {
        return $this->setData('store_id', $storeId);
    }

    public function getActiveFrom(): ?string
    {
        $v = $this->getData('active_from');
        return $v === null ? null : (string) $v;
    }

    public function setActiveFrom(?string $date): self
    {
        return $this->setData('active_from', $date);
    }

    public function getActiveTo(): ?string
    {
        $v = $this->getData('active_to');
        return $v === null ? null : (string) $v;
    }

    public function setActiveTo(?string $date): self
    {
        return $this->setData('active_to', $date);
    }

    public function getReferenceType(): string
    {
        return (string) ($this->getData('reference_type') ?: 'url');
    }

    public function setReferenceType(string $type): self
    {
        return $this->setData('reference_type', $type);
    }

    public function getReferenceValue(): ?string
    {
        $v = $this->getData('reference_value');
        return $v === null ? null : (string) $v;
    }

    public function setReferenceValue(?string $value): self
    {
        return $this->setData('reference_value', $value);
    }

    public function getCreatedAt(): ?string
    {
        $v = $this->getData('created_at');
        return $v === null ? null : (string) $v;
    }
}
