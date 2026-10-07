<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Score\Check;

use Panth\AdvancedSEO\Model\Score\Check\DuplicateCheck;
use Panth\AdvancedSEO\Model\Score\EmbeddingIndex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DuplicateCheckTest extends TestCase
{
    private const CONTEXT = [
        'entity_type' => 'product',
        'entity_id'   => 7,
        'store_id'    => 2,
        'meta'        => ['title' => 'Blue Shirt', 'description' => 'Soft cotton'],
    ];

    private function check(array $neighbours): DuplicateCheck
    {
        $index = $this->createMock(EmbeddingIndex::class);
        $index->expects($this->once())->method('vectorize')->with('Blue Shirt Soft cotton')->willReturn([0.6, 0.8]);
        $index->expects($this->once())->method('store')->with('product', 7, 2, [0.6, 0.8]);
        $index->expects($this->once())->method('findSimilar')->with('product', 2, [0.6, 0.8], 7, 5)->willReturn($neighbours);

        return new DuplicateCheck($index);
    }

    public function testCode(): void
    {
        $this->assertSame('duplicate', (new DuplicateCheck($this->createStub(EmbeddingIndex::class)))->getCode());
    }

    public function testEmptyMetaIsNotEvaluated(): void
    {
        $index = $this->createMock(EmbeddingIndex::class);
        $index->expects($this->never())->method('store');

        $result = (new DuplicateCheck($index))->run(['meta' => ['title' => ' ', 'description' => '']]);

        $this->assertSame(0.0, $result['max']);
        $this->assertSame('Meta is empty - duplication not evaluated', $result['message']);
    }

    public function testNoNeighboursScoresFull(): void
    {
        $result = $this->check([])->run(self::CONTEXT);

        $this->assertSame(100.0, $result['score']);
        $this->assertSame('No duplicates detected (highest similarity 0.00)', $result['message']);
        $this->assertSame([], $result['details']['duplicates']);
    }

    public static function similarityProvider(): array
    {
        return [
            'safe threshold' => [0.7, 100.0],
            'midway'         => [0.8, 50.0],
            'duplicate edge' => [0.9, 0.0],
        ];
    }

    #[DataProvider('similarityProvider')]
    public function testScoreFallsLinearlyBetweenSafeAndDuplicate(float $similarity, float $expected): void
    {
        $result = $this->check([['similarity' => $similarity]])->run(self::CONTEXT);

        $this->assertEqualsWithDelta($expected, $result['score'], 0.0001);
        $this->assertEqualsWithDelta($similarity, $result['details']['top_similarity'], 0.0001);
    }

    public function testNearDuplicatesAreListed(): void
    {
        $neighbours = [
            ['entity_id' => 3, 'similarity' => 0.92],
            ['entity_id' => 4, 'similarity' => 0.97],
            ['entity_id' => 5, 'similarity' => 0.5],
        ];

        $result = $this->check($neighbours)->run(self::CONTEXT);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('2 near-duplicate(s) found, highest similarity 0.97', $result['message']);
        $this->assertSame([3, 4], array_column($result['details']['duplicates'], 'entity_id'));
    }
}
