<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api;

/**
 * Produces robots directives (meta + header + robots.txt body) including
 * per-LLM-bot allow/deny lines (GPTBot, ClaudeBot, Google-Extended, CCBot, ...).
 */
interface RobotsPolicyInterface
{
    /**
     * @return string Value for `<meta name="robots">` (e.g. "index,follow" or "noindex,nofollow").
     */
    public function getMetaRobots(string $entityType, int $entityId, int $storeId): string;

    /**
     * @return string Value for the `X-Robots-Tag` HTTP header.
     */
    public function getHeaderRobots(string $entityType, int $entityId, int $storeId): string;

    /**
     * @return string Full body of robots.txt for the given store.
     */
    public function getRobotsTxt(int $storeId): string;

    /**
     * @return array<string,bool> Map of LLM-bot user-agent => allowed
     */
    public function getLlmBotPolicy(int $storeId): array;
}
