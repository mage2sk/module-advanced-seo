<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api\Data;

/**
 * Meta template data interface.
 *
 * Represents a row in `panth_seo_template`. A template defines fallback meta
 * title / description / keywords / og / twitter / robots for an entity type
 * (product|category|cms|other) at a given store scope. Values may contain
 * Smarty-lite variables like `{{name}}`, `{{price}}`, `{{category.name}}`.
 */
interface MetaTemplateInterface
{
    public const TEMPLATE_ID     = 'template_id';
    public const STORE_ID        = 'store_id';
    public const ENTITY_TYPE     = 'entity_type';
    public const SCOPE           = 'scope';
    public const NAME            = 'name';
    public const META_TITLE      = 'meta_title';
    public const META_DESCRIPTION = 'meta_description';
    public const META_KEYWORDS   = 'meta_keywords';
    public const OG_TITLE        = 'og_title';
    public const OG_DESCRIPTION  = 'og_description';
    public const OG_IMAGE        = 'og_image';
    public const TWITTER_CARD    = 'twitter_card';
    public const ROBOTS          = 'robots';
    public const PRIORITY        = 'priority';
    public const IS_ACTIVE        = 'is_active';
    public const IS_CRON_ENABLED  = 'is_cron_enabled';
    public const LAST_APPLIED_AT  = 'last_applied_at';
    public const APPLY_COUNT      = 'apply_count';
    public const CREATED_AT       = 'created_at';
    public const UPDATED_AT       = 'updated_at';

    public function getTemplateId(): ?int;

    public function setTemplateId(int $id): self;

    public function getStoreId(): int;

    public function setStoreId(int $storeId): self;

    public function getEntityType(): string;

    public function setEntityType(string $type): self;

    public function getScope(): string;

    public function setScope(string $scope): self;

    public function getName(): string;

    public function setName(string $name): self;

    public function getMetaTitle(): ?string;

    public function setMetaTitle(?string $value): self;

    public function getMetaDescription(): ?string;

    public function setMetaDescription(?string $value): self;

    public function getMetaKeywords(): ?string;

    public function setMetaKeywords(?string $value): self;

    public function getOgTitle(): ?string;

    public function setOgTitle(?string $value): self;

    public function getOgDescription(): ?string;

    public function setOgDescription(?string $value): self;

    public function getOgImage(): ?string;

    public function setOgImage(?string $value): self;

    public function getTwitterCard(): ?string;

    public function setTwitterCard(?string $value): self;

    public function getRobots(): ?string;

    public function setRobots(?string $value): self;

    public function getPriority(): int;

    public function setPriority(int $priority): self;

    public function isActive(): bool;

    public function setIsActive(bool $flag): self;

    public function isCronEnabled(): bool;

    public function setIsCronEnabled(bool $flag): self;

    public function getLastAppliedAt(): ?string;

    public function setLastAppliedAt(?string $timestamp): self;

    public function getApplyCount(): int;

    public function setApplyCount(int $count): self;
}
