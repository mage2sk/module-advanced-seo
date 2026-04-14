<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\FilterMeta;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedSEO\Model\ResourceModel\CategoryFilterMeta as CategoryFilterMetaResource;

class CategoryFilterMeta extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(CategoryFilterMetaResource::class);
    }

    public function getCategoryId(): int
    {
        return (int) $this->getData('category_id');
    }

    public function setCategoryId(int $categoryId): self
    {
        return $this->setData('category_id', $categoryId);
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

    public function getStoreId(): int
    {
        return (int) $this->getData('store_id');
    }

    public function setStoreId(int $storeId): self
    {
        return $this->setData('store_id', $storeId);
    }

    public function getMetaTitle(): ?string
    {
        $v = $this->getData('meta_title');
        return $v === null ? null : (string) $v;
    }

    public function setMetaTitle(?string $value): self
    {
        return $this->setData('meta_title', $value);
    }

    public function getMetaDescription(): ?string
    {
        $v = $this->getData('meta_description');
        return $v === null ? null : (string) $v;
    }

    public function setMetaDescription(?string $value): self
    {
        return $this->setData('meta_description', $value);
    }

    public function getMetaKeywords(): ?string
    {
        $v = $this->getData('meta_keywords');
        return $v === null ? null : (string) $v;
    }

    public function setMetaKeywords(?string $value): self
    {
        return $this->setData('meta_keywords', $value);
    }
}
