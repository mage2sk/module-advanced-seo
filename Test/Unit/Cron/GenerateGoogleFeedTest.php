<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Cron;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Cron\GenerateGoogleFeed;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Feed\GoogleMerchantFeedBuilder;
use Panth\AdvancedSEO\Model\Feed\ProfileBasedFeedBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GenerateGoogleFeedTest extends TestCase
{
    private array $built = [];

    private array $logged = [];

    private array $profileArgs = [];

    private function cron(
        array $profileResults,
        array $profiles,
        array $storeIds,
        array $feedEnabled,
        bool $moduleEnabled = true,
        array $failing = []
    ): GenerateGoogleFeed {
        $this->built = [];
        $this->logged = [];
        $this->profileArgs = [];

        $legacy = $this->createStub(GoogleMerchantFeedBuilder::class);
        $legacy->method('buildToFile')->willReturnCallback(
            function (int $storeId, string $path) use ($failing): string {
                if (in_array($storeId, $failing, true)) {
                    throw new \RuntimeException('disk full');
                }
                $this->built[$storeId] = $path;
                return $path;
            }
        );

        $profileBuilder = $this->createStub(ProfileBasedFeedBuilder::class);
        $profileBuilder->method('generateAllActive')->willReturnCallback(
            function (?int $storeId = null, bool $cronOnly = false) use ($profileResults): array {
                $this->profileArgs = [$storeId, $cronOnly];
                return $profileResults;
            }
        );
        $profileBuilder->method('loadProfile')->willReturnCallback(
            static fn(int $id): ?array => $profiles[$id] ?? null
        );

        $stores = [];
        foreach ($storeIds as $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $store->method('getCode')->willReturn('s' . $id);
            $stores[] = $store;
        }
        $repository = $this->createStub(StoreRepositoryInterface::class);
        $repository->method('getList')->willReturn($stores);

        $directoryList = $this->createStub(DirectoryList::class);
        $directoryList->method('getPath')->willReturn('/media');

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($moduleEnabled);
        $config->method('isMerchantFeedEnabled')->willReturnCallback(
            static fn(?int $id = null): bool => in_array($id, $feedEnabled, true)
        );

        $logger = $this->createStub(LoggerInterface::class);
        foreach (['info', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message) use ($level): void {
                    $this->logged[] = [$level, $message];
                }
            );
        }

        return new GenerateGoogleFeed($legacy, $profileBuilder, $repository, $directoryList, $config, $logger);
    }

    public function testProfilesRunInCronModeAndTheirStoresSkipTheLegacyFeed(): void
    {
        $this->cron(
            [7 => ['file_path' => '/media/f.xml', 'product_count' => 3, 'generation_time' => 1.234]],
            [7 => ['store_id' => '1']],
            [0, 1, 2],
            [1, 2]
        )->execute();

        $this->assertSame([null, true], $this->profileArgs);
        $this->assertSame([2 => '/media/panth_seo/google_feed_s2.xml'], $this->built);
        $this->assertSame(
            ['info', 'Panth SEO Feed Cron: profile #7 generated -> /media/f.xml (3 products, 1.23s)'],
            $this->logged[0]
        );
    }

    public function testAFailedProfileIsLoggedAndDoesNotClaimItsStore(): void
    {
        $this->cron([4 => ['error' => 'bad template']], [4 => ['store_id' => 1]], [1], [1])->execute();

        $this->assertSame(['error', 'Panth SEO Feed Cron: profile #4 failed: bad template'], $this->logged[0]);
        $this->assertArrayHasKey(1, $this->built);
    }

    public function testAProfileThatCannotBeReloadedLeavesTheStoreToTheLegacyFeed(): void
    {
        $this->cron([9 => []], [], [1], [1])->execute();

        $this->assertSame([1 => '/media/panth_seo/google_feed_s1.xml'], $this->built);
        $this->assertStringContainsString('profile #9 generated ->  (0 products, 0.00s)', $this->logged[0][1]);
    }

    public function testDisabledModuleOrFeedSkipsTheLegacyFeed(): void
    {
        $this->cron([], [], [1, 2], [1])->execute();
        $this->assertSame([1], array_keys($this->built));

        $this->cron([], [], [1], [1], false)->execute();
        $this->assertSame([], $this->built);
    }

    public function testALegacyFeedFailureIsLoggedPerStore(): void
    {
        $this->cron([], [], [1, 2], [1, 2], true, [1])->execute();

        $this->assertSame([2], array_keys($this->built));
        $this->assertSame(
            ['error', 'Panth SEO: Legacy Google feed failed for store "s1": disk full'],
            $this->logged[0]
        );
        $this->assertStringContainsString('Legacy Google feed generated for store "s2"', $this->logged[1][1]);
    }
}
