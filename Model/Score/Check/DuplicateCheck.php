<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Score\Check;

use Panth\AdvancedSEO\Model\Score\CheckInterface;
use Panth\AdvancedSEO\Model\Score\EmbeddingIndex;

class DuplicateCheck implements CheckInterface
{
    private const SIMILARITY_SAFE = 0.7;

    private const SIMILARITY_DUPLICATE = 0.9;

    public function __construct(
        private readonly EmbeddingIndex $embeddings
    ) {
    }

    public function getCode(): string
    {
        return 'duplicate';
    }

    public function run(array $context): array
    {
        $entityType = (string)($context['entity_type'] ?? '');
        $entityId = (int)($context['entity_id'] ?? 0);
        $storeId = (int)($context['store_id'] ?? 0);
        $title = (string)($context['meta']['title'] ?? '');
        $description = (string)($context['meta']['description'] ?? '');

        $text = trim($title . ' ' . $description);
        if ($text === '') {
            return [
                'score' => 0.0,
                'max' => 0.0,
                'message' => 'Meta is empty - duplication not evaluated',
            ];
        }

        $vector = $this->embeddings->vectorize($text);
        $this->embeddings->store($entityType, $entityId, $storeId, $vector);

        $neighbours = $this->embeddings->findSimilar($entityType, $storeId, $vector, $entityId, 5);

        $topSim = 0.0;
        $dupes = [];
        foreach ($neighbours as $n) {
            if ($n['similarity'] > $topSim) {
                $topSim = (float)$n['similarity'];
            }
            if ($n['similarity'] >= 0.9) {
                $dupes[] = $n;
            }
        }

        $score = $this->similarityScore($topSim);

        $message = $dupes === []
            ? sprintf('No duplicates detected (highest similarity %.2f)', $topSim)
            : sprintf('%d near-duplicate(s) found, highest similarity %.2f', count($dupes), $topSim);

        return [
            'score' => $score,
            'max' => 100.0,
            'message' => $message,
            'details' => [
                'top_similarity' => $topSim,
                'duplicates' => $dupes,
            ],
        ];
    }

    private function similarityScore(float $similarity): float
    {
        if ($similarity <= self::SIMILARITY_SAFE) {
            return 100.0;
        }

        if ($similarity >= self::SIMILARITY_DUPLICATE) {
            return 0.0;
        }

        $span = self::SIMILARITY_DUPLICATE - self::SIMILARITY_SAFE;

        return max(0.0, min(100.0, (1.0 - (($similarity - self::SIMILARITY_SAFE) / $span)) * 100.0));
    }
}
