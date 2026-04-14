<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Score\Check;

use Panth\AdvancedSEO\Model\Score\CheckInterface;
use Panth\AdvancedSEO\Model\Score\EmbeddingIndex;

/**
 * Detects duplicate or near-duplicate meta content using cosine similarity
 * over vectors stored in panth_seo_meta_embedding.
 */
class DuplicateCheck implements CheckInterface
{
    public function __construct(
        private readonly EmbeddingIndex $embeddings
    ) {
    }

    public function getCode(): string
    {
        return 'duplicate';
    }

    /**
     * @param array<string,mixed> $context
     * @return array{score:float, max:float, message:string, details?:array<string,mixed>}
     */
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
                'max' => 100.0,
                'message' => 'Meta is empty — cannot evaluate duplication',
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

        // Score = (1 - topSim) * 100, clamped.
        $score = max(0.0, min(100.0, (1.0 - $topSim) * 100.0));

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
}
