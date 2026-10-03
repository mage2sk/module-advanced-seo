<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\InternalLinking;

class PageRank
{
    private const MAX_ITERATIONS = 30;
    private const DAMPING        = 0.85;
    private const EPSILON        = 1e-4;

    public function __construct(
        private readonly Graph $graph
    ) {
    }

    public function compute(int $storeId): array
    {
        $adjacency = $this->graph->build($storeId);
        if (empty($adjacency)) {
            return [];
        }

        $nodes = [];
        foreach ($adjacency as $src => $targets) {
            $nodes[$src] = true;
            foreach ($targets as $dst => $_) {
                $nodes[$dst] = true;
            }
        }
        $nodeIds = array_keys($nodes);
        $n = count($nodeIds);
        if ($n === 0) {
            return [];
        }

        $base = 1.0 / $n;
        $rank = array_fill_keys($nodeIds, $base);

        $outSum = [];
        foreach ($adjacency as $src => $targets) {
            $sum = 0.0;
            foreach ($targets as $w) {
                $sum += (float) $w;
            }
            $outSum[$src] = $sum > 0 ? $sum : 0.0;
        }

        $baseline = (1.0 - self::DAMPING) / $n;

        for ($iter = 0; $iter < self::MAX_ITERATIONS; $iter++) {
            $next = array_fill_keys($nodeIds, $baseline);
            $danglingMass = 0.0;
            foreach ($rank as $node => $pr) {
                if (!isset($adjacency[$node]) || ($outSum[$node] ?? 0.0) <= 0.0) {
                    $danglingMass += $pr;
                }
            }
            $danglingContribution = self::DAMPING * ($danglingMass / $n);

            foreach ($adjacency as $src => $targets) {
                $srcPr = $rank[$src] ?? $base;
                $total = $outSum[$src] ?? 0.0;
                if ($total <= 0.0) {
                    continue;
                }
                foreach ($targets as $dst => $w) {
                    $next[$dst] += self::DAMPING * $srcPr * ($w / $total);
                }
            }
            if ($danglingContribution > 0) {
                foreach ($next as $k => $v) {
                    $next[$k] = $v + $danglingContribution;
                }
            }

            $delta = 0.0;
            foreach ($next as $k => $v) {
                $delta += abs($v - ($rank[$k] ?? 0.0));
            }
            $rank = $next;
            if ($delta < self::EPSILON) {
                break;
            }
        }

        return $rank;
    }
}
