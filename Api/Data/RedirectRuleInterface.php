<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api\Data;

/**
 * Redirect rule data interface (row of panth_seo_redirect).
 */
interface RedirectRuleInterface
{
    public const REDIRECT_ID  = 'redirect_id';
    public const STORE_ID     = 'store_id';
    public const MATCH_TYPE   = 'match_type';
    public const PATTERN      = 'pattern';
    public const TARGET       = 'target';
    public const STATUS_CODE  = 'status_code';
    public const PRIORITY     = 'priority';
    public const IS_ACTIVE    = 'is_active';
    public const HIT_COUNT    = 'hit_count';
    public const LAST_HIT_AT  = 'last_hit_at';
    public const CREATED_AT   = 'created_at';
    public const UPDATED_AT   = 'updated_at';
    public const IS_AUTO_GENERATED = 'is_auto_generated';

    public const MATCH_LITERAL     = 'literal';
    public const MATCH_REGEX       = 'regex';
    public const MATCH_MAINTENANCE = 'maintenance';

    public function getRedirectId(): ?int;

    public function getStoreId(): int;

    public function setStoreId(int $storeId): self;

    public function getMatchType(): string;

    public function setMatchType(string $type): self;

    public function getPattern(): string;

    public function setPattern(string $pattern): self;

    public function getTarget(): string;

    public function setTarget(string $target): self;

    public function getStatusCode(): int;

    public function setStatusCode(int $code): self;

    public function getPriority(): int;

    public function setPriority(int $priority): self;

    public function isActive(): bool;

    public function setIsActive(bool $flag): self;

    public function getHitCount(): int;

    public function setHitCount(int $count): self;

    public function getLastHitAt(): ?string;

    public function setLastHitAt(?string $at): self;
}
