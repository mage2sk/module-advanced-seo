<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta;

use Magento\Framework\DataObject;
use Panth\AdvancedSEO\Api\Data\ResolvedMetaInterface;

/**
 * DTO implementation of ResolvedMetaInterface. Not persisted directly;
 * ResolvedRepository handles reads/writes to `panth_seo_resolved`.
 */
class ResolvedMeta extends DataObject implements ResolvedMetaInterface
{
    public function getResolvedId(): ?int
    {
        $value = $this->getData(self::RESOLVED_ID);
        return $value === null ? null : (int) $value;
    }

    public function getStoreId(): int
    {
        return (int) $this->getData(self::STORE_ID);
    }

    public function setStoreId(int $storeId): self
    {
        $this->setData(self::STORE_ID, $storeId);
        return $this;
    }

    public function getEntityType(): string
    {
        return (string) $this->getData(self::ENTITY_TYPE);
    }

    public function setEntityType(string $type): self
    {
        $this->setData(self::ENTITY_TYPE, $type);
        return $this;
    }

    public function getEntityId(): int
    {
        return (int) $this->getData(self::ENTITY_ID);
    }

    public function setEntityId(int $id): self
    {
        $this->setData(self::ENTITY_ID, $id);
        return $this;
    }

    public function getMetaTitle(): ?string
    {
        $value = $this->getData(self::META_TITLE);
        return $value === null ? null : (string) $value;
    }

    public function setMetaTitle(?string $value): self
    {
        $this->setData(self::META_TITLE, $value);
        return $this;
    }

    public function getMetaDescription(): ?string
    {
        $value = $this->getData(self::META_DESCRIPTION);
        return $value === null ? null : (string) $value;
    }

    public function setMetaDescription(?string $value): self
    {
        $this->setData(self::META_DESCRIPTION, $value);
        return $this;
    }

    public function getMetaKeywords(): ?string
    {
        $value = $this->getData(self::META_KEYWORDS);
        return $value === null ? null : (string) $value;
    }

    public function setMetaKeywords(?string $value): self
    {
        $this->setData(self::META_KEYWORDS, $value);
        return $this;
    }

    public function getCanonicalUrl(): ?string
    {
        $value = $this->getData(self::CANONICAL_URL);
        return $value === null ? null : (string) $value;
    }

    public function setCanonicalUrl(?string $url): self
    {
        $this->setData(self::CANONICAL_URL, $url);
        return $this;
    }

    public function getRobots(): ?string
    {
        $value = $this->getData(self::ROBOTS);
        return $value === null ? null : (string) $value;
    }

    public function setRobots(?string $value): self
    {
        $this->setData(self::ROBOTS, $value);
        return $this;
    }

    /** @return array<string,mixed> */
    public function getOgPayload(): array
    {
        $value = $this->getData(self::OG_PAYLOAD);
        return is_array($value) ? $value : [];
    }

    public function setOgPayload(array $payload): self
    {
        $this->setData(self::OG_PAYLOAD, $payload);
        return $this;
    }

    /** @return array<string,mixed> */
    public function getJsonldPayload(): array
    {
        $value = $this->getData(self::JSONLD_PAYLOAD);
        return is_array($value) ? $value : [];
    }

    public function setJsonldPayload(array $payload): self
    {
        $this->setData(self::JSONLD_PAYLOAD, $payload);
        return $this;
    }

    /** @return array<string,mixed> */
    public function getHreflangPayload(): array
    {
        $value = $this->getData(self::HREFLANG_PAYLOAD);
        return is_array($value) ? $value : [];
    }

    public function setHreflangPayload(array $payload): self
    {
        $this->setData(self::HREFLANG_PAYLOAD, $payload);
        return $this;
    }

    public function getSource(): string
    {
        return (string) ($this->getData(self::SOURCE) ?? 'fallback');
    }

    public function setSource(string $source): self
    {
        $this->setData(self::SOURCE, $source);
        return $this;
    }
}
