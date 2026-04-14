<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api;

use Panth\AdvancedSEO\Api\Data\RedirectRuleInterface;

/**
 * Matches a request path against the redirect table.
 */
interface RedirectMatcherInterface
{
    /**
     * @return RedirectRuleInterface|null The matching rule or null if none match.
     */
    public function match(string $requestPath, int $storeId): ?RedirectRuleInterface;

    /**
     * Records a hit on a matched rule (increments counter, updates last_hit_at).
     */
    public function recordHit(int $redirectId): void;

    /**
     * Logs a 404 for clustering.
     */
    public function log404(string $requestPath, int $storeId, ?string $referer = null): void;
}
