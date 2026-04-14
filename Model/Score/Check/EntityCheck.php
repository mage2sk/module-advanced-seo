<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Score\Check;

use Panth\AdvancedSEO\Model\Score\CheckInterface;

/**
 * Checks presence of key entity attributes: brand, sku, image, alt.
 */
class EntityCheck implements CheckInterface
{
    public function getCode(): string
    {
        return 'entity';
    }

    /**
     * @param array<string,mixed> $context
     * @return array{score:float, max:float, message:string, details?:array<string,mixed>}
     */
    public function run(array $context): array
    {
        $attrs = (array)($context['attributes'] ?? []);
        $type = (string)($context['entity_type'] ?? '');

        $required = match ($type) {
            'product' => ['name', 'sku', 'brand', 'image'],
            'category' => ['name', 'image'],
            'cms_page' => ['name'],
            default => ['name'],
        };

        $present = [];
        $missing = [];
        foreach ($required as $key) {
            $val = $attrs[$key] ?? null;
            if ($val === null || $val === '' || $val === 0 || $val === '0') {
                $missing[] = $key;
            } else {
                $present[] = $key;
            }
        }

        // Alt text check — look for <img ... alt="..."> in content
        $altScore = 100.0;
        $content = (string)($context['content'] ?? '');
        if (preg_match_all('/<img\b[^>]*>/i', $content, $m)) {
            $imgs = $m[0];
            $withAlt = 0;
            foreach ($imgs as $img) {
                if (preg_match('/\balt\s*=\s*"[^"]+"/i', $img)) {
                    $withAlt++;
                }
            }
            if (count($imgs) > 0) {
                $altScore = ($withAlt / count($imgs)) * 100.0;
            }
        }

        $required[] = 'alt';
        $baseScore = (count($present) / max(1, count($required) - 1)) * 100.0;
        $score = ($baseScore * 0.7) + ($altScore * 0.3);

        return [
            'score' => $score,
            'max' => 100.0,
            'message' => $missing === []
                ? 'All required entity attributes present'
                : 'Missing: ' . implode(', ', $missing),
            'details' => [
                'present' => $present,
                'missing' => $missing,
                'alt_score' => $altScore,
            ],
        ];
    }
}
