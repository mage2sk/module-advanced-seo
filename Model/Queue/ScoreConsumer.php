<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Queue;

use Panth\AdvancedSEO\Api\SeoScorerInterface;
use Psr\Log\LoggerInterface;

/**
 * Queue consumer for topic `panth_seo.score_entity`.
 *
 * Message payload: JSON { "entity_type":..., "entity_id":..., "store_id":... }
 */
class ScoreConsumer
{
    public function __construct(
        private readonly SeoScorerInterface $scorer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $message): void
    {
        $decoded = json_decode($message, true);
        if (!is_array($decoded)) {
            $this->logger->warning('Panth SEO score: invalid message', ['message' => $message]);
            return;
        }
        try {
            $this->scorer->score(
                (string)($decoded['entity_type'] ?? ''),
                (int)($decoded['entity_id'] ?? 0),
                (int)($decoded['store_id'] ?? 0)
            );
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO score consumer failed: ' . $e->getMessage());
        }
    }
}
