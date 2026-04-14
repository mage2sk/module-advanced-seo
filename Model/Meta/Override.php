<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedSEO\Model\ResourceModel\Override as OverrideResource;

class Override extends AbstractModel
{
    public const OVERRIDE_ID      = 'override_id';
    public const STORE_ID         = 'store_id';
    public const ENTITY_TYPE      = 'entity_type';
    public const ENTITY_ID        = 'entity_id';
    public const META_TITLE       = 'meta_title';
    public const META_DESCRIPTION = 'meta_description';
    public const META_KEYWORDS    = 'meta_keywords';
    public const CANONICAL_URL    = 'canonical_url';
    public const ROBOTS           = 'robots';
    public const AI_GENERATED     = 'ai_generated';
    public const AI_APPROVED      = 'ai_approved';

    protected $_idFieldName = 'override_id';

    protected function _construct(): void
    {
        $this->_init(OverrideResource::class);
    }

    public function getOverrideId(): ?int
    {
        $v = $this->getData(self::OVERRIDE_ID);
        return $v === null ? null : (int) $v;
    }

    public function getStoreId(): int
    {
        return (int) $this->getData(self::STORE_ID);
    }

    public function getEntityType(): string
    {
        return (string) $this->getData(self::ENTITY_TYPE);
    }

    public function getEntityId(): int
    {
        return (int) $this->getData(self::ENTITY_ID);
    }

    public function getMetaTitle(): ?string
    {
        $v = $this->getData(self::META_TITLE);
        return $v === null ? null : (string) $v;
    }

    public function getMetaDescription(): ?string
    {
        $v = $this->getData(self::META_DESCRIPTION);
        return $v === null ? null : (string) $v;
    }

    public function getMetaKeywords(): ?string
    {
        $v = $this->getData(self::META_KEYWORDS);
        return $v === null ? null : (string) $v;
    }

    public function getCanonicalUrl(): ?string
    {
        $v = $this->getData(self::CANONICAL_URL);
        return $v === null ? null : (string) $v;
    }

    public function getRobots(): ?string
    {
        $v = $this->getData(self::ROBOTS);
        return $v === null ? null : (string) $v;
    }

    public function isAiGenerated(): bool
    {
        return (bool) $this->getData(self::AI_GENERATED);
    }

    public function isAiApproved(): bool
    {
        return (bool) $this->getData(self::AI_APPROVED);
    }
}
