<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Hreflang;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedSEO\Api\Data\HreflangMapInterface;
use Panth\AdvancedSEO\Model\ResourceModel\HreflangMember as MemberResource;

class Member extends AbstractModel implements HreflangMapInterface
{
    protected $_idFieldName = 'member_id';

    protected function _construct(): void
    {
        $this->_init(MemberResource::class);
    }

    public function getMemberId(): ?int
    {
        $id = $this->getData(self::MEMBER_ID);
        return $id === null ? null : (int) $id;
    }

    public function getGroupId(): int
    {
        return (int) $this->getData(self::GROUP_ID);
    }

    public function setGroupId(int $id): self
    {
        return $this->setData(self::GROUP_ID, $id);
    }

    public function getStoreId(): int
    {
        return (int) $this->getData(self::STORE_ID);
    }

    public function setStoreId(int $id): self
    {
        return $this->setData(self::STORE_ID, $id);
    }

    public function getEntityType(): string
    {
        return (string) $this->getData(self::ENTITY_TYPE);
    }

    public function setEntityType(string $type): self
    {
        return $this->setData(self::ENTITY_TYPE, $type);
    }

    public function getEntityId()
    {
        return (int) $this->getData(self::ENTITY_ID);
    }

    public function setEntityId($entityId)
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    public function getLocale(): string
    {
        return (string) $this->getData(self::LOCALE);
    }

    public function setLocale(string $locale): self
    {
        return $this->setData(self::LOCALE, $locale);
    }

    public function getUrl(): string
    {
        return (string) $this->getData(self::URL);
    }

    public function setUrl(string $url): self
    {
        return $this->setData(self::URL, $url);
    }

    public function isDefault(): bool
    {
        return (bool) $this->getData(self::IS_DEFAULT);
    }

    public function setIsDefault(bool $flag): self
    {
        return $this->setData(self::IS_DEFAULT, $flag);
    }
}
