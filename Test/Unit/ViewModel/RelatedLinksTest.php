<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\ViewModel;

use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Model\InternalLinking\Suggester;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use Panth\AdvancedSEO\ViewModel\RelatedLinks;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RelatedLinksTest extends TestCase
{
    use ConnectionRecorder;

    private array $warnings = [];

    private array $suggestArgs = [];

    private function viewModel(array|\Throwable $suggestions, array $fetchAll = []): RelatedLinks
    {
        $this->warnings = [];
        $this->suggestArgs = [];
        $suggester = $this->createStub(Suggester::class);
        $suggester->method('suggest')->willReturnCallback(
            function (string $type, int $id, int $store, int $limit = 5) use ($suggestions): array {
                $this->suggestArgs = [$type, $id, $store, $limit];
                if ($suggestions instanceof \Throwable) {
                    throw $suggestions;
                }
                return $suggestions;
            }
        );
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message): void {
            $this->warnings[] = $message;
        });

        return new RelatedLinks($suggester, $storeManager, $this->recordingResource([], $fetchAll), $logger);
    }

    public function testSuggestionsAreHydratedWithUrlsAndNames(): void
    {
        $vm = $this->viewModel(
            [
                ['type' => 'product', 'id' => '5', 'score' => '0.9'],
                ['type' => 'category', 'id' => 3, 'score' => 0.5],
                ['type' => 'product', 'id' => 6, 'score' => 0.4],
                ['type' => 'cms', 'id' => 2, 'score' => 0.1],
            ],
            [
                [['entity_id' => '5', 'request_path' => '/bottle.html'], ['entity_id' => '5', 'request_path' => 'dupe.html'], ['entity_id' => '6', 'request_path' => 'cup.html']],
                [['entity_id' => '5', 'value' => 'Bottle']],
                [['entity_id' => '3', 'request_path' => 'gear.html']],
                [['entity_id' => '3', 'value' => 'Gear']],
                [],
            ]
        );

        $links = $vm->getSuggestions('product', 1, 3);

        $this->assertSame(['product', 1, 1, 3], $this->suggestArgs);
        $this->assertSame([
            ['label' => 'Bottle', 'url' => 'https://shop.test/bottle.html', 'score' => 0.9],
            ['label' => 'Gear', 'url' => 'https://shop.test/gear.html', 'score' => 0.5],
            ['label' => '#6', 'url' => 'https://shop.test/cup.html', 'score' => 0.4],
        ], $links);
        $this->assertContains(['entity_id IN (?)', [5, 6]], $this->db['where']);
        $this->assertContains(['redirect_type = ?', 0], $this->db['where']);
    }

    public function testNoSuggestionsMeansNoQueries(): void
    {
        $this->assertSame([], $this->viewModel([])->getSuggestions('category', 2));
        $this->assertSame([], $this->db['where']);
    }

    public function testFailuresAreLoggedAndReturnNothing(): void
    {
        $this->assertSame([], $this->viewModel(new \RuntimeException('graph missing'))->getSuggestions('product', 1));
        $this->assertSame(['[PanthSEO] related links viewmodel failed: graph missing'], $this->warnings);
    }
}
