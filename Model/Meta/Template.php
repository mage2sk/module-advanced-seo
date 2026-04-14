<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedSEO\Api\Data\MetaTemplateInterface;
use Panth\AdvancedSEO\Model\ResourceModel\Template as TemplateResource;

class Template extends AbstractModel implements MetaTemplateInterface
{
    protected $_idFieldName = 'template_id';

    protected function _construct(): void
    {
        $this->_init(TemplateResource::class);
    }

    public function getTemplateId(): ?int
    {
        $value = $this->getData(self::TEMPLATE_ID);
        return $value === null ? null : (int) $value;
    }

    public function setTemplateId(int $id): self
    {
        return $this->setData(self::TEMPLATE_ID, $id);
    }

    public function getStoreId(): int
    {
        return (int) $this->getData(self::STORE_ID);
    }

    public function setStoreId(int $storeId): self
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    public function getEntityType(): string
    {
        return (string) $this->getData(self::ENTITY_TYPE);
    }

    public function setEntityType(string $type): self
    {
        return $this->setData(self::ENTITY_TYPE, $type);
    }

    public function getScope(): string
    {
        return (string) ($this->getData(self::SCOPE) ?? 'default');
    }

    public function setScope(string $scope): self
    {
        return $this->setData(self::SCOPE, $scope);
    }

    public function getName(): string
    {
        return (string) $this->getData(self::NAME);
    }

    public function setName(string $name): self
    {
        return $this->setData(self::NAME, $name);
    }

    public function getMetaTitle(): ?string
    {
        $v = $this->getData(self::META_TITLE);
        return $v === null ? null : (string) $v;
    }

    public function setMetaTitle(?string $value): self
    {
        return $this->setData(self::META_TITLE, $value);
    }

    public function getMetaDescription(): ?string
    {
        $v = $this->getData(self::META_DESCRIPTION);
        return $v === null ? null : (string) $v;
    }

    public function setMetaDescription(?string $value): self
    {
        return $this->setData(self::META_DESCRIPTION, $value);
    }

    public function getMetaKeywords(): ?string
    {
        $v = $this->getData(self::META_KEYWORDS);
        return $v === null ? null : (string) $v;
    }

    public function setMetaKeywords(?string $value): self
    {
        return $this->setData(self::META_KEYWORDS, $value);
    }

    public function getOgTitle(): ?string
    {
        $v = $this->getData(self::OG_TITLE);
        return $v === null ? null : (string) $v;
    }

    public function setOgTitle(?string $value): self
    {
        return $this->setData(self::OG_TITLE, $value);
    }

    public function getOgDescription(): ?string
    {
        $v = $this->getData(self::OG_DESCRIPTION);
        return $v === null ? null : (string) $v;
    }

    public function setOgDescription(?string $value): self
    {
        return $this->setData(self::OG_DESCRIPTION, $value);
    }

    public function getOgImage(): ?string
    {
        $v = $this->getData(self::OG_IMAGE);
        return $v === null ? null : (string) $v;
    }

    public function setOgImage(?string $value): self
    {
        return $this->setData(self::OG_IMAGE, $value);
    }

    public function getTwitterCard(): ?string
    {
        $v = $this->getData(self::TWITTER_CARD);
        return $v === null ? null : (string) $v;
    }

    public function setTwitterCard(?string $value): self
    {
        return $this->setData(self::TWITTER_CARD, $value);
    }

    public function getRobots(): ?string
    {
        $v = $this->getData(self::ROBOTS);
        return $v === null ? null : (string) $v;
    }

    public function setRobots(?string $value): self
    {
        return $this->setData(self::ROBOTS, $value);
    }

    public function getPriority(): int
    {
        return (int) ($this->getData(self::PRIORITY) ?? 10);
    }

    public function setPriority(int $priority): self
    {
        return $this->setData(self::PRIORITY, $priority);
    }

    public function isActive(): bool
    {
        return (bool) $this->getData(self::IS_ACTIVE);
    }

    public function setIsActive(bool $flag): self
    {
        return $this->setData(self::IS_ACTIVE, $flag ? 1 : 0);
    }

    public function isCronEnabled(): bool
    {
        return (bool) $this->getData(self::IS_CRON_ENABLED);
    }

    public function setIsCronEnabled(bool $flag): self
    {
        return $this->setData(self::IS_CRON_ENABLED, $flag ? 1 : 0);
    }

    public function getLastAppliedAt(): ?string
    {
        $v = $this->getData(self::LAST_APPLIED_AT);
        return $v === null ? null : (string) $v;
    }

    public function setLastAppliedAt(?string $timestamp): self
    {
        return $this->setData(self::LAST_APPLIED_AT, $timestamp);
    }

    public function getApplyCount(): int
    {
        return (int) ($this->getData(self::APPLY_COUNT) ?? 0);
    }

    public function setApplyCount(int $count): self
    {
        return $this->setData(self::APPLY_COUNT, $count);
    }
}
