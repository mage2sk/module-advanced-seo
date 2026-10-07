<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Console\Command;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Console\Command\GenerateFeedCommand;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Feed\GoogleMerchantFeedBuilder;
use Panth\AdvancedSEO\Model\Feed\ProfileBasedFeedBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateFeedCommandTest extends TestCase
{
    private array $legacy = [];

    private array $generated = [];

    private array $loadArgs = [];

    private function tester(
        array $profiles = [],
        array|\Throwable $stats = [],
        array $feedEnabled = [1, 2],
        array $legacyFailing = []
    ): CommandTester {
        $this->legacy = [];
        $this->generated = [];
        $this->loadArgs = [];

        $legacy = $this->createStub(GoogleMerchantFeedBuilder::class);
        $legacy->method('buildToFile')->willReturnCallback(function (int $storeId, string $path) use ($legacyFailing): string {
            if (in_array($storeId, $legacyFailing, true)) {
                throw new \RuntimeException('cannot write');
            }
            $this->legacy[] = [$storeId, $path];
            return $path;
        });

        $profileBuilder = $this->createStub(ProfileBasedFeedBuilder::class);
        $profileBuilder->method('loadActiveProfiles')->willReturnCallback(
            function (?int $storeId = null) use ($profiles): array {
                $this->loadArgs[] = $storeId;
                return $profiles;
            }
        );
        $result = function ($key) use ($stats): array {
            $this->generated[] = $key;
            if ($stats instanceof \Throwable) {
                throw $stats;
            }
            return $stats;
        };
        $profileBuilder->method('generateById')->willReturnCallback(static fn(int $id): array => $result($id));
        $profileBuilder->method('generate')->willReturnCallback(static fn(array $p): array => $result((int) $p['feed_id']));

        $stores = [];
        foreach ([0 => 'admin', 1 => 'default', 2 => 'fr'] as $id => $code) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $store->method('getCode')->willReturn($code);
            $stores[$id] = $store;
        }
        $repository = $this->createStub(StoreRepositoryInterface::class);
        $repository->method('getList')->willReturn(array_values($stores));
        $repository->method('getById')->willReturnCallback(static function (int $id) use ($stores) {
            if (!isset($stores[$id])) {
                throw new NoSuchEntityException(__('nope'));
            }
            return $stores[$id];
        });
        $repository->method('get')->willReturnCallback(static function (string $code) use ($stores) {
            foreach ($stores as $store) {
                if ($store->getCode() === $code) {
                    return $store;
                }
            }
            throw new NoSuchEntityException(__('nope'));
        });

        $directoryList = $this->createStub(DirectoryList::class);
        $directoryList->method('getPath')->willReturn('/pub/media');

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('isMerchantFeedEnabled')->willReturnCallback(
            static fn(?int $id = null): bool => in_array($id, $feedEnabled, true)
        );

        return new CommandTester(new GenerateFeedCommand(
            $legacy,
            $profileBuilder,
            $repository,
            $this->createStub(AppState::class),
            $directoryList,
            $config
        ));
    }

    public function testASingleProfileIsGeneratedById(): void
    {
        $tester = $this->tester([], ['file_path' => '/f.xml', 'product_count' => 12, 'file_size' => 2048, 'generation_time' => 0.5]);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--feed' => '7']));
        $this->assertSame([7], $this->generated);
        $this->assertStringContainsString('Generating feed profile #7...', $tester->getDisplay());
        $this->assertStringContainsString('-> /f.xml (12 products, 2 KB, 0.50s)', $tester->getDisplay());
    }

    public function testFileSizesAreHumanReadable(): void
    {
        $tester = $this->tester([], ['file_path' => '/f.xml', 'file_size' => 500]);
        $tester->execute(['--feed' => '1']);
        $this->assertStringContainsString('(0 products, 500 B, 0.00s)', $tester->getDisplay());

        $tester = $this->tester([], ['file_size' => 3 * 1048576]);
        $tester->execute(['--feed' => '1']);
        $this->assertStringContainsString('-> unknown (0 products, 3 MB', $tester->getDisplay());
    }

    public function testAProfileErrorIsPrintedButStillSucceeds(): void
    {
        $tester = $this->tester([], ['error' => 'no products']);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--feed' => '7']));
        $this->assertStringContainsString('Error: no products', $tester->getDisplay());
    }

    public function testAThrowingProfileFails(): void
    {
        $tester = $this->tester([], new \RuntimeException('missing profile'));

        $this->assertSame(Command::FAILURE, $tester->execute(['--feed' => '7']));
        $this->assertStringContainsString('Failed: missing profile', $tester->getDisplay());
    }

    public function testAllActiveProfilesRunWhenNoOptionIsGiven(): void
    {
        $profiles = [
            ['feed_id' => 1, 'name' => 'Google', 'store_id' => 1, 'output_format' => 'csv'],
            ['feed_id' => 2],
        ];
        $tester = $this->tester($profiles, ['file_path' => '/x']);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame([null], $this->loadArgs);
        $this->assertSame([1, 2], $this->generated);
        $this->assertStringContainsString('Generating feed #1 "Google" (store 1, csv)...', $tester->getDisplay());
        $this->assertStringContainsString('Generating feed #2 "Unnamed" (store 0, xml)...', $tester->getDisplay());
        $this->assertSame([], $this->legacy);
    }

    public function testAFailingProfileInABatchSetsTheFailureCode(): void
    {
        $tester = $this->tester([['feed_id' => 1]], new \RuntimeException('bad'));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('  Failed: bad', $tester->getDisplay());
    }

    public function testWithoutProfilesTheLegacyFeedIsBuiltForEnabledStores(): void
    {
        $tester = $this->tester([], [], [1]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame([[1, '/pub/media/panth_seo/google_feed_default.xml']], $this->legacy);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('No feed profiles found.', $display);
        $this->assertStringContainsString('Skipping store "fr" (id 2): merchant feed disabled.', $display);
        $this->assertStringContainsString('-> /pub/media/panth_seo/google_feed_default.xml', $display);
    }

    public function testStoreOptionUsesThatStoresProfilesOrLegacyFeed(): void
    {
        $tester = $this->tester([['feed_id' => 4]], ['file_path' => '/y']);
        $tester->execute(['--store' => 'fr']);
        $this->assertSame([2], $this->loadArgs);
        $this->assertSame([4], $this->generated);

        $tester = $this->tester();
        $this->assertSame(Command::SUCCESS, $tester->execute(['--store' => '2']));
        $this->assertSame([[2, '/pub/media/panth_seo/google_feed_fr.xml']], $this->legacy);
    }

    public function testUnknownStoreFails(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute(['--store' => 'xx']));
        $this->assertStringContainsString('Store not found: xx', $tester->getDisplay());
    }

    public function testALegacyFeedFailureSetsTheFailureCode(): void
    {
        $tester = $this->tester([], [], [1, 2], [1]);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertSame([[2, '/pub/media/panth_seo/google_feed_fr.xml']], $this->legacy);
        $this->assertStringContainsString('Failed: cannot write', $tester->getDisplay());
    }
}
