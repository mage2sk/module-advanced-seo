<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Controller\Feed;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Controller\Feed\Google;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Feed\GoogleMerchantFeedBuilder;
use Panth\AdvancedSEO\Model\Feed\ProfileBasedFeedBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GoogleTest extends TestCase
{
    private string $media = '';

    private array $response = [];

    private array $cacheSaves = [];

    private int $builds = 0;

    protected function setUp(): void
    {
        $this->media = sys_get_temp_dir() . '/panth_seo_feed_' . uniqid('', true);
        mkdir($this->media . '/panth_seo/feeds', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->media . '/panth_seo/feeds/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (['/panth_seo/feeds', '/panth_seo', ''] as $sub) {
            if (is_dir($this->media . $sub)) {
                rmdir($this->media . $sub);
            }
        }
    }

    private function controller(array $options = []): Google
    {
        $this->response = ['code' => 200, 'headers' => [], 'body' => null];
        $this->cacheSaves = [];
        $this->builds = 0;

        $raw = $this->createStub(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($raw) {
            $this->response['code'] = $code;
            return $raw;
        });
        $raw->method('setHeader')->willReturnCallback(function ($name, $value) use ($raw) {
            $this->response['headers'][$name] = $value;
            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function ($value) use ($raw) {
            $this->response['body'] = $value;
            return $raw;
        });
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        $legacy = $this->createStub(GoogleMerchantFeedBuilder::class);
        $legacy->method('build')->willReturnCallback(function (int $storeId): string {
            $this->builds++;
            return '<rss store="' . $storeId . '"/>';
        });

        $profiles = $this->createStub(ProfileBasedFeedBuilder::class);
        $profiles->method('loadProfile')->willReturn($options['profile'] ?? null);
        $profiles->method('generate')->willReturnCallback(function (array $profile) use ($options): array {
            if (($options['generate'] ?? '') === 'fail') {
                throw new \RuntimeException('no products');
            }
            if (($options['generate'] ?? '') === 'write') {
                file_put_contents($this->media . '/panth_seo/feeds/' . $profile['filename'], '<feed/>');
            }
            return [];
        });

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn($options['cached'] ?? false);
        $cache->method('save')->willReturnCallback(function ($data, $id, $tags = [], $ttl = null): bool {
            $this->cacheSaves[] = [$data, $id, $tags, $ttl];
            return true;
        });

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($options['enabled'] ?? true);
        $config->method('isMerchantFeedEnabled')->willReturn($options['feed_enabled'] ?? true);

        $directoryList = $this->createStub(DirectoryList::class);
        $directoryList->method('getPath')->willReturn($this->media);

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $key === 'id' ? ($options['id'] ?? 0) : $default);

        return new Google($rawFactory, $legacy, $profiles, $storeManager, $cache, $config, $directoryList, $request);
    }

    private static function profile(array $overrides = []): array
    {
        return $overrides + ['is_active' => 1, 'store_id' => 1, 'filename' => 'g.xml', 'output_format' => 'xml', 'compress' => ''];
    }

    public function testDisabledFeedReturns404(): void
    {
        $this->controller(['feed_enabled' => false])->execute();

        $this->assertSame(404, $this->response['code']);
        $this->assertSame('Feed not available.', $this->response['body']);
    }

    public function testLegacyFeedIsBuiltAndCached(): void
    {
        $this->controller()->execute();

        $this->assertSame('<rss store="1"/>', $this->response['body']);
        $this->assertSame('application/xml; charset=utf-8', $this->response['headers']['Content-Type']);
        $this->assertSame([['<rss store="1"/>', 'panth_seo_google_feed_1', ['PANTH_SEO_GOOGLE_FEED'], 3600]], $this->cacheSaves);
    }

    public function testCachedLegacyFeedSkipsTheBuilder(): void
    {
        $this->controller(['cached' => '<rss cached="1"/>'])->execute();

        $this->assertSame('<rss cached="1"/>', $this->response['body']);
        $this->assertSame(0, $this->builds);
        $this->assertSame([], $this->cacheSaves);
    }

    public function testProfileFeedServesTheExistingFile(): void
    {
        file_put_contents($this->media . '/panth_seo/feeds/g.xml', '<items/>');

        $this->controller(['id' => 5, 'profile' => self::profile()])->execute();

        $this->assertSame(200, $this->response['code']);
        $this->assertSame('<items/>', $this->response['body']);
        $this->assertSame('8', $this->response['headers']['Content-Length']);
        $this->assertSame('nosniff', $this->response['headers']['X-Content-Type-Options']);
    }

    public static function contentTypes(): array
    {
        return [
            'gzip' => [['compress' => 'gzip'], 'g.xml.gz', 'application/gzip'],
            'zip'  => [['compress' => 'zip'], 'g.xml.zip', 'application/zip'],
            'csv'  => [['output_format' => 'csv'], 'g.xml', 'text/csv; charset=utf-8'],
        ];
    }

    #[DataProvider('contentTypes')]
    public function testProfileFeedContentTypes(array $overrides, string $file, string $type): void
    {
        file_put_contents($this->media . '/panth_seo/feeds/' . $file, 'data');

        $this->controller(['id' => 5, 'profile' => self::profile($overrides)])->execute();

        $this->assertSame($type, $this->response['headers']['Content-Type']);
    }

    public function testAMissingFileIsGeneratedOnDemand(): void
    {
        $this->controller(['id' => 5, 'profile' => self::profile(), 'generate' => 'write'])->execute();
        $this->assertSame('<feed/>', $this->response['body']);

        $this->controller(['id' => 5, 'profile' => self::profile(['filename' => 'other.xml'])])->execute();
        $this->assertSame([404, 'Feed file not found.'], [$this->response['code'], $this->response['body']]);

        $this->controller(['id' => 5, 'profile' => self::profile(['filename' => 'x.xml']), 'generate' => 'fail'])->execute();
        $this->assertSame([500, 'Feed generation failed.'], [$this->response['code'], $this->response['body']]);
    }

    public function testUnavailableProfilesAreRejected(): void
    {
        $this->controller(['id' => 5])->execute();
        $this->assertSame([404, 'Feed not found or inactive.'], [$this->response['code'], $this->response['body']]);

        $this->controller(['id' => 5, 'profile' => self::profile(['is_active' => 0])])->execute();
        $this->assertSame(404, $this->response['code']);

        $this->controller(['id' => 5, 'profile' => self::profile(['store_id' => 2])])->execute();
        $this->assertSame([404, 'Feed not available for this store.'], [$this->response['code'], $this->response['body']]);

        $this->controller(['id' => 5, 'profile' => self::profile(['filename' => '..'])])->execute();
        $this->assertSame([400, 'Invalid filename.'], [$this->response['code'], $this->response['body']]);
    }

    public function testPathTraversalIsNeutralised(): void
    {
        file_put_contents($this->media . '/panth_seo/feeds/g.xml', '<safe/>');

        $this->controller(['id' => 5, 'profile' => self::profile(['filename' => '../../g.xml'])])->execute();

        $this->assertSame('<safe/>', $this->response['body']);
    }
}
