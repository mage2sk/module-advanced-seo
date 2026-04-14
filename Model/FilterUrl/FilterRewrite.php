<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\FilterUrl;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedSEO\Model\ResourceModel\FilterRewrite as FilterRewriteResource;

class FilterRewrite extends AbstractModel
{
    protected $_idFieldName = 'rewrite_id';

    protected function _construct(): void
    {
        $this->_init(FilterRewriteResource::class);
    }

    public function getRewriteId(): ?int
    {
        $id = $this->getData('rewrite_id');
        return $id !== null ? (int) $id : null;
    }

    public function getAttributeCode(): string
    {
        return (string) $this->getData('attribute_code');
    }

    public function setAttributeCode(string $code): self
    {
        return $this->setData('attribute_code', $code);
    }

    public function getOptionId(): int
    {
        return (int) $this->getData('option_id');
    }

    public function setOptionId(int $optionId): self
    {
        return $this->setData('option_id', $optionId);
    }

    public function getOptionLabel(): string
    {
        return (string) $this->getData('option_label');
    }

    public function setOptionLabel(string $label): self
    {
        return $this->setData('option_label', $label);
    }

    public function getRewriteSlug(): string
    {
        return (string) $this->getData('rewrite_slug');
    }

    public function setRewriteSlug(string $slug): self
    {
        return $this->setData('rewrite_slug', $slug);
    }

    public function getStoreId(): int
    {
        return (int) $this->getData('store_id');
    }

    public function setStoreId(int $storeId): self
    {
        return $this->setData('store_id', $storeId);
    }

    public function getIsActive(): bool
    {
        return (bool) $this->getData('is_active');
    }

    public function setIsActive(bool $active): self
    {
        return $this->setData('is_active', $active);
    }
}
