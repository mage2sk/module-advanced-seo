<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\InternalLinking;

use Panth\AdvancedSEO\Model\InternalLinking\Graph;
use Panth\AdvancedSEO\Model\InternalLinking\PageRank;
use PHPUnit\Framework\TestCase;

class PageRankTest extends TestCase
{
    private function pageRank(array $adjacency): PageRank
    {
        $graph = $this->createStub(Graph::class);
        $graph->method('build')->willReturn($adjacency);
        return new PageRank($graph);
    }

    public function testEmptyGraphReturnsEmpty(): void
    {
        $this->assertSame([], $this->pageRank([])->compute(1));
    }

    public function testSymmetricGraphGivesEqualRanks(): void
    {
        $ranks = $this->pageRank([
            'a' => ['b' => 1.0],
            'b' => ['a' => 1.0],
        ])->compute(1);

        $this->assertEqualsWithDelta(0.5, $ranks['a'], 1e-6);
        $this->assertEqualsWithDelta(0.5, $ranks['b'], 1e-6);
    }

    public function testHubReceivesHighestRankAndMassIsConserved(): void
    {
        $ranks = $this->pageRank([
            'x' => ['hub' => 1.0],
            'y' => ['hub' => 1.0],
            'z' => ['hub' => 1.0],
            'hub' => ['x' => 1.0],
        ])->compute(1);

        $this->assertCount(4, $ranks);
        $this->assertSame('hub', array_search(max($ranks), $ranks, true));
        $this->assertEqualsWithDelta(1.0, array_sum($ranks), 1e-3);
    }

    public function testDanglingNodesRedistributeMass(): void
    {
        $ranks = $this->pageRank([
            'a' => ['sink' => 1.0],
            'zero' => ['a' => 0.0],
        ])->compute(1);

        $this->assertArrayHasKey('sink', $ranks);
        $this->assertGreaterThan($ranks['zero'], $ranks['sink']);
        $this->assertEqualsWithDelta(1.0, array_sum($ranks), 1e-3);
    }
}
