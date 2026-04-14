<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Redirect;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedSEO\Api\Data\RedirectRuleInterface;
use Panth\AdvancedSEO\Model\ResourceModel\Redirect as RedirectResource;

class RedirectModel extends AbstractModel implements RedirectRuleInterface
{
    protected $_idFieldName = 'redirect_id';
    protected $_eventPrefix = 'panth_seo_redirect';

    protected function _construct(): void
    {
        $this->_init(RedirectResource::class);
    }

    public function getRedirectId(): ?int
    {
        $v = $this->getData(self::REDIRECT_ID);
        return $v === null ? null : (int) $v;
    }

    public function getStoreId(): int
    {
        return (int) $this->getData(self::STORE_ID);
    }

    public function setStoreId(int $storeId): self
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    public function getMatchType(): string
    {
        return (string) $this->getData(self::MATCH_TYPE);
    }

    public function setMatchType(string $type): self
    {
        return $this->setData(self::MATCH_TYPE, $type);
    }

    public function getPattern(): string
    {
        return (string) $this->getData(self::PATTERN);
    }

    public function setPattern(string $pattern): self
    {
        return $this->setData(self::PATTERN, $pattern);
    }

    public function getTarget(): string
    {
        return (string) $this->getData(self::TARGET);
    }

    public function setTarget(string $target): self
    {
        return $this->setData(self::TARGET, $target);
    }

    public function getStatusCode(): int
    {
        return (int) ($this->getData(self::STATUS_CODE) ?: 301);
    }

    public function setStatusCode(int $code): self
    {
        return $this->setData(self::STATUS_CODE, $code);
    }

    public function getPriority(): int
    {
        return (int) $this->getData(self::PRIORITY);
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
        return $this->setData(self::IS_ACTIVE, $flag);
    }

    public function getHitCount(): int
    {
        return (int) $this->getData(self::HIT_COUNT);
    }

    public function setHitCount(int $count): self
    {
        return $this->setData(self::HIT_COUNT, $count);
    }

    public function getLastHitAt(): ?string
    {
        $v = $this->getData(self::LAST_HIT_AT);
        return $v === null ? null : (string) $v;
    }

    public function setLastHitAt(?string $at): self
    {
        return $this->setData(self::LAST_HIT_AT, $at);
    }
}
