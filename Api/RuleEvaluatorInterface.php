<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api;

/**
 * Evaluates SEO rules (condition combine + actions) for a given entity
 * context and returns the aggregated action payload.
 */
interface RuleEvaluatorInterface
{
    /**
     * @param  string              $entityType
     * @param  int                 $entityId
     * @param  int                 $storeId
     * @param  array<string,mixed> $context  Optional pre-built context to avoid re-fetch.
     * @return array<string,mixed> {
     *     template_id?:int,
     *     canonical?:string,
     *     robots?:string,
     *     noindex?:bool,
     *     matched_rules:int[]
     * }
     */
    public function evaluate(
        string $entityType,
        int $entityId,
        int $storeId,
        array $context = []
    ): array;
}
