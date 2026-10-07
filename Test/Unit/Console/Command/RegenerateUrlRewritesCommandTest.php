<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Console\Command;

use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Console\Command\RegenerateUrlRewritesCommand;
use Panth\AdvancedSEO\Model\Url\RewriteRegenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RegenerateUrlRewritesCommandTest extends TestCase
{
    private array $calls = [];

    private function tester(): CommandTester
    {
        $this->calls = [];
        $regenerator = $this->createStub(RewriteRegenerator::class);
        $regenerator->method('regenerateProducts')->willReturnCallback(function (int $storeId, array $ids = []): int {
            $this->calls[] = ['product', $storeId, array_values($ids)];
            return 4;
        });
        $regenerator->method('regenerateCategories')->willReturnCallback(function (int $storeId, array $ids = []): int {
            $this->calls[] = ['category', $storeId, array_values($ids)];
            return 2;
        });

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('3');
        $repository = $this->createStub(StoreRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(static function (string $code) use ($store) {
            if ($code !== 'fr') {
                throw new NoSuchEntityException(__('nope'));
            }
            return $store;
        });

        return new CommandTester(new RegenerateUrlRewritesCommand($regenerator, $repository, $this->createStub(AppState::class)));
    }

    public function testDefaultsRegenerateEverythingForAllStores(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame([['product', 0, []], ['category', 0, []]], $this->calls);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Regenerating URL rewrites (entity=all, store=0, ids=all)...', $display);
        $this->assertStringContainsString('Done. Regenerated 6 URL rewrite(s).', $display);
    }

    public function testProductsOnlyWithStoreCodeAndIdList(): void
    {
        $tester = $this->tester();

        $tester->execute(['--entity' => 'product', '--store' => 'fr', '--ids' => '5, 0,x,9']);

        $this->assertSame([['product', 3, [5, 9]]], $this->calls);
        $this->assertStringContainsString('(entity=product, store=3, ids=5,9)', $tester->getDisplay());
    }

    public function testCategoriesOnlyWithAnIdRange(): void
    {
        $tester = $this->tester();

        $tester->execute(['--entity' => 'category', '--store' => '2', '--id-range' => '3-5']);

        $this->assertSame([['category', 2, [3, 4, 5]]], $this->calls);
    }

    public static function invalidInputs(): array
    {
        return [
            'entity'        => [['--entity' => 'brand'], 'Invalid --entity value "brand"'],
            'store'         => [['--store' => 'nowhere'], 'Store with code "nowhere" does not exist.'],
            'both id forms' => [['--ids' => '1', '--id-range' => '1-2'], 'mutually exclusive'],
            'empty ids'     => [['--ids' => '0,-3,abc'], 'at least one valid positive integer'],
            'range format'  => [['--id-range' => '1..5'], '"start-end" format'],
            'range zero'    => [['--id-range' => '0-5'], 'start must be >= 1'],
            'range reverse' => [['--id-range' => '9-5'], 'end must be >= start'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsRejected(array $input, string $message): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::INVALID, $tester->execute($input));
        $this->assertStringContainsString($message, $tester->getDisplay());
        $this->assertSame([], $this->calls);
    }
}
