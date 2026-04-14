<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api\Data;

/**
 * Hreflang map: a single (group_id, store_id, entity_type, entity_id, locale, url)
 * member as stored in `panth_seo_hreflang_member`.
 */
interface HreflangMapInterface
{
    public const MEMBER_ID    = 'member_id';
    public const GROUP_ID     = 'group_id';
    public const STORE_ID     = 'store_id';
    public const ENTITY_TYPE  = 'entity_type';
    public const ENTITY_ID    = 'entity_id';
    public const LOCALE       = 'locale';
    public const URL          = 'url';
    public const IS_DEFAULT   = 'is_default';

    public function getMemberId(): ?int;

    public function getGroupId(): int;

    public function setGroupId(int $id): self;

    public function getStoreId(): int;

    public function setStoreId(int $id): self;

    public function getEntityType(): string;

    public function setEntityType(string $type): self;

    public function getEntityId();

    public function setEntityId($id);

    public function getLocale(): string;

    public function setLocale(string $locale): self;

    public function getUrl(): string;

    public function setUrl(string $url): self;

    public function isDefault(): bool;

    public function setIsDefault(bool $flag): self;
}
