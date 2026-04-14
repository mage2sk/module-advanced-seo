<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Score;

/**
 * Individual SEO score check.
 * Each check returns a value in 0..100 and a human-readable message.
 */
interface CheckInterface
{
    /**
     * Unique code used for reporting / weight lookup.
     */
    public function getCode(): string;

    /**
     * @param array<string,mixed> $context {entity_type, entity_id, store_id, meta:{title,description,...}, content, ...}
     * @return array{score:float, max:float, message:string, details?:array<string,mixed>}
     */
    public function run(array $context): array;
}
