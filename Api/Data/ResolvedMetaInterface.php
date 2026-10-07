<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api\Data;

/**
 * Resolved meta data interface.
 *
 * Represents the precomputed SEO payload for a (store_id, entity_type, entity_id)
 * triple as produced by the `panth_seo_resolved_meta` indexer.
 */
interface ResolvedMetaInterface
{
    public const RESOLVED_ID      = 'resolved_id';
    public const STORE_ID         = 'store_id';
    public const ENTITY_TYPE      = 'entity_type';
    public const ENTITY_ID        = 'entity_id';
    public const META_TITLE       = 'meta_title';
    public const META_DESCRIPTION = 'meta_description';
    public const META_KEYWORDS    = 'meta_keywords';
    public const CANONICAL_URL    = 'canonical_url';
    public const ROBOTS           = 'robots';
    public const OG_PAYLOAD       = 'og_payload';
    public const JSONLD_PAYLOAD   = 'jsonld_payload';
    public const HREFLANG_PAYLOAD = 'hreflang_payload';
    public const SOURCE           = 'source';
    public const UPDATED_AT       = 'updated_at';

    public function getResolvedId(): ?int;

    public function getStoreId(): int;

    public function setStoreId(int $storeId): self;

    public function getEntityType(): string;

    public function setEntityType(string $type): self;

    public function getEntityId(): int;

    public function setEntityId(int $id): self;

    public function getMetaTitle(): ?string;

    public function setMetaTitle(?string $value): self;

    public function getMetaDescription(): ?string;

    public function setMetaDescription(?string $value): self;

    public function getMetaKeywords(): ?string;

    public function setMetaKeywords(?string $value): self;

    public function getCanonicalUrl(): ?string;

    public function setCanonicalUrl(?string $url): self;

    public function getRobots(): ?string;

    public function setRobots(?string $value): self;

    /** @return array<string,mixed> */
    public function getOgPayload(): array;

    /** @param array<string,mixed> $payload */
    public function setOgPayload(array $payload): self;

    /** @return array<string,mixed> */
    public function getJsonldPayload(): array;

    /** @param array<string,mixed> $payload */
    public function setJsonldPayload(array $payload): self;

    /** @return array<string,mixed> */
    public function getHreflangPayload(): array;

    /** @param array<string,mixed> $payload */
    public function setHreflangPayload(array $payload): self;

    public function getSource(): string;

    public function setSource(string $source): self;
}
