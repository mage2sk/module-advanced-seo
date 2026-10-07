<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Feed;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Model\Feed\CsvFeedWriter;
use Panth\AdvancedSEO\Model\Feed\FieldResolver;
use Panth\AdvancedSEO\Model\Feed\FtpDelivery;
use Panth\AdvancedSEO\Model\Feed\ProfileBasedFeedBuilder;
use Panth\AdvancedSEO\Model\Feed\XmlFeedWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProfileBasedFeedBuilderTest extends TestCase
{
    private string $dir;
    private array $collectionCalls = [];
    private array $updates = [];
    private array $wheres = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/panth_seo_profile_feed_' . uniqid('', true);
        $this->collectionCalls = [];
        $this->updates = [];
        $this->wheres = [];
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function product(string $sku, string $type = 'simple', int $id = 1): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn($sku);
        $product->method('getTypeId')->willReturn($type);
        $product->method('getId')->willReturn($id);
        return $product;
    }

    private function collection(array $items, ?Product $firstItem = null): ProductCollection
    {
        $select = $this->createStub(Select::class);
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $collection = $this->createStub(ProductCollection::class);
        $track = function (string $method) use (&$collection) {
            return function (...$args) use ($method, &$collection) {
                $this->collectionCalls[] = [$method, $args];
                return $collection;
            };
        };
        foreach ([
            'setStoreId', 'addStoreFilter', 'addAttributeToFilter', 'addAttributeToSelect', 'addUrlRewrite',
            'addFinalPrice', 'setFlag', 'addCategoriesFilter', 'addFieldToFilter', 'setPageSize', 'setCurPage',
            'addIdFilter',
        ] as $method) {
            $collection->method($method)->willReturnCallback($track($method));
        }
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getTable')->willReturnArgument(0);
        $collection->method('getItems')->willReturn($items);
        if ($firstItem !== null) {
            $collection->method('getFirstItem')->willReturn($firstItem);
        }
        return $collection;
    }

    private function connection(array $fields, array $profiles = [], mixed $profileRow = false, bool $tables = true, bool $updateFails = false): AdapterInterface
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $val = null) use ($select) {
            $this->wheres[] = [$cond, $val];
            return $select;
        });

        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('isTableExists')->willReturn($tables);
        $conn->method('select')->willReturn($select);
        $conn->method('fetchRow')->willReturn($profileRow);
        $conn->method('fetchAll')->willReturnCallback(function () use ($fields, $profiles) {
            $last = end($this->wheres);
            return ($last && str_starts_with((string) $last[0], 'feed_id')) ? $fields : $profiles;
        });
        if ($updateFails) {
            $conn->method('update')->willThrowException(new \RuntimeException('locked'));
        } else {
            $conn->method('update')->willReturnCallback(function ($table, $data, $where) {
                $this->updates[] = [$table, $data, $where];
                return 1;
            });
        }
        return $conn;
    }

    private function builder(
        array $collections,
        AdapterInterface $connection,
        ?FieldResolver $resolver = null,
        ?LoggerInterface $logger = null,
        ?FtpDelivery $delivery = null,
        ?ConfigurableResource $configurable = null
    ): ProfileBasedFeedBuilder {
        $factory = $this->createStub(ProductCollectionFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls(...$collections);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/media/');
        $store->method('getName')->willReturn('Main');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $dir = $this->dir;
        $mediaDir = $this->createStub(WriteInterface::class);
        $mediaDir->method('getAbsolutePath')->willReturnCallback(static fn ($path) => $dir . '/' . $path);
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($mediaDir);

        if ($resolver === null) {
            $resolver = $this->createStub(FieldResolver::class);
            $resolver->method('resolve')->willReturnCallback(
                static function (array $config, Product $product, int $storeId, ?Product $parent) {
                    return match ($config['feed_field']) {
                        'id' => $product->getSku(),
                        'link' => $product->getSku() === 'NOLINK' ? '' : 'https://shop.test/' . $product->getSku(),
                        'brand' => $parent ? 'parent-' . $parent->getSku() : 'own',
                        default => 'x',
                    };
                }
            );
        }

        return new ProfileBasedFeedBuilder(
            $factory,
            $configurable ?? $this->createStub(ConfigurableResource::class),
            $resource,
            $storeManager,
            $filesystem,
            $resolver,
            new XmlFeedWriter(),
            new CsvFeedWriter(),
            $logger ?? $this->createStub(LoggerInterface::class),
            $delivery ?? $this->createStub(FtpDelivery::class)
        );
    }

    private static function fields(): array
    {
        return [
            ['feed_field' => 'id', 'source_type' => 'attribute', 'source_value' => 'sku', 'is_required' => 1],
            ['feed_field' => 'link', 'source_type' => 'template', 'source_value' => 'product_url', 'is_required' => 1],
            ['feed_field' => '', 'source_type' => 'static', 'source_value' => 'ignored'],
            ['feed_field' => 'mpn', 'source_type' => 'attribute', 'source_value' => 'mpn_code'],
        ];
    }

    public function testGenerateCsvSkipsIncompleteRowsAppliesUtmAndGzips(): void
    {
        $delivery = $this->createMock(FtpDelivery::class);
        $delivery->expects($this->once())->method('deliver')
            ->with($this->arrayHasKey('delivery_enabled'), $this->stringEndsWith('feed.csv.gz'));

        $builder = $this->builder(
            [$this->collection([$this->product('A'), $this->product('NOLINK')])],
            $this->connection(self::fields()),
            null,
            null,
            $delivery
        );

        $result = $builder->generate([
            'feed_id' => 5,
            'store_id' => 1,
            'output_format' => 'csv',
            'filename' => 'feed.csv',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring',
            'compress' => 'gzip',
            'delivery_enabled' => 1,
            'include_out_of_stock' => 1,
            'include_disabled' => 1,
            'include_not_visible' => 1,
            'category_filter' => '3, x ,4',
            'attribute_set_filter' => '0',
        ]);

        $this->assertSame(1, $result['product_count']);
        $this->assertSame('https://shop.test/media/panth_seo/feeds/feed.csv.gz', $result['file_url']);
        $this->assertFileExists($result['file_path']);
        $this->assertGreaterThan(0, $result['file_size']);

        $csv = gzdecode((string) file_get_contents($result['file_path']));
        $this->assertStringContainsString('id,link,mpn', $csv);
        $this->assertStringContainsString('A,https://shop.test/A?utm_source=google&utm_medium=cpc&utm_campaign=spring,x', $csv);
        $this->assertStringNotContainsString('NOLINK', $csv);

        $methods = array_column($this->collectionCalls, 0);
        $this->assertContains('setFlag', $methods);
        $this->assertNotContains('addFinalPrice', $methods);
        $this->assertNotContains('addAttributeToFilter', $methods);
        $this->assertNotContains('addFieldToFilter', $methods);
        $categories = array_values(array_filter($this->collectionCalls, static fn ($c) => $c[0] === 'addCategoriesFilter'));
        $this->assertSame([['in' => [0 => 3, 2 => 4]]], $categories[0][1]);

        $this->assertCount(1, $this->updates);
        $this->assertSame('panth_seo_feed_profile', $this->updates[0][0]);
        $this->assertSame(1, $this->updates[0][1]['product_count']);
        $this->assertSame(['feed_id = ?' => 5], $this->updates[0][2]);
    }

    public function testGenerateXmlWithDefaultFilenameZipAndFilters(): void
    {
        $delivery = $this->createMock(FtpDelivery::class);
        $delivery->expects($this->never())->method('deliver');

        $builder = $this->builder(
            [$this->collection([$this->product('B')])],
            $this->connection(self::fields()),
            null,
            null,
            $delivery
        );

        $result = $builder->generate([
            'feed_id' => 7,
            'store_id' => 1,
            'compress' => 'zip',
            'utm_source' => 'fb',
            'attribute_set_filter' => '4,9',
            'delivery_enabled' => 1,
        ], false);

        $this->assertSame(1, $result['product_count']);
        $this->assertStringEndsWith('feed_7.xml.zip', $result['file_path']);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($result['file_path']));
        $xml = (string) $zip->getFromName('feed_7.xml');
        $zip->close();
        $this->assertStringContainsString('<link>https://shop.test/B?utm_source=fb</link>', $xml);

        $methods = array_column($this->collectionCalls, 0);
        $this->assertContains('addFinalPrice', $methods);
        $this->assertNotContains('setFlag', $methods);
        $filters = array_values(array_filter($this->collectionCalls, static fn ($c) => $c[0] === 'addAttributeToFilter'));
        $this->assertSame(['status', 1], array_slice($filters[0][1], 0, 2));
        $this->assertSame(['visibility', ['in' => [2, 4]]], array_slice($filters[1][1], 0, 2));
        $fieldFilters = array_values(array_filter($this->collectionCalls, static fn ($c) => $c[0] === 'addFieldToFilter'));
        $this->assertSame(['attribute_set_id', ['in' => [4, 9]]], $fieldFilters[0][1]);
    }

    public function testInvalidFilenameThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid file name for feed profile #3.');

        $this->builder([], $this->connection(self::fields()))
            ->generate(['feed_id' => 3, 'store_id' => 1, 'filename' => '.hidden.php']);
    }

    public function testMissingFieldMappingsThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No field mappings found for feed profile #3.');

        $this->builder([], $this->connection([], [], false, false))
            ->generate(['feed_id' => 3, 'store_id' => 1]);
    }

    public function testGenerateByIdThrowsWhenProfileMissing(): void
    {
        $builder = $this->builder([], $this->connection([], [], false, true));
        $this->assertNull($builder->loadProfile(9));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Feed profile #9 not found.');
        $builder->generateById(9);
    }

    public function testGenerateByIdUsesLoadedProfile(): void
    {
        $builder = $this->builder(
            [$this->collection([$this->product('C')])],
            $this->connection(self::fields(), [], ['feed_id' => '8', 'store_id' => '1', 'output_format' => 'csv'])
        );

        $result = $builder->generateById(8);
        $this->assertSame(1, $result['product_count']);
        $this->assertStringEndsWith('feed_8.csv', $result['file_path']);
    }

    public function testLoadActiveProfilesFiltersAndMissingTable(): void
    {
        $builder = $this->builder([], $this->connection([], [['feed_id' => 1]]));
        $this->assertSame([['feed_id' => 1]], $builder->loadActiveProfiles(2, true));
        $this->assertSame([['is_active = ?', 1], ['store_id = ?', 2], ['cron_enabled = ?', 1]], $this->wheres);

        $this->assertSame([], $this->builder([], $this->connection([], [], false, false))->loadActiveProfiles());
    }

    public function testGenerateAllActiveRecordsErrors(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'Panth SEO Feed: generation failed for profile #4 "Broken": Invalid file name for feed profile #4.'
        );

        $builder = $this->builder(
            [$this->collection([$this->product('D')])],
            $this->connection(self::fields(), [
                ['feed_id' => 4, 'store_id' => 1, 'name' => 'Broken', 'filename' => 'bad name.exe'],
                ['feed_id' => 6, 'store_id' => 1, 'output_format' => 'csv'],
            ]),
            null,
            $logger
        );

        $results = $builder->generateAllActive();

        $this->assertSame(['error' => 'Invalid file name for feed profile #4.'], $results[4]);
        $this->assertSame(1, $results[6]['product_count']);
    }

    public function testParentProductsAreLoadedForParentAttributes(): void
    {
        $fields = [
            ['feed_field' => 'id', 'source_type' => 'attribute', 'source_value' => 'sku'],
            ['feed_field' => 'brand', 'source_type' => 'parent_attribute', 'source_value' => 'manufacturer'],
        ];

        $configurable = $this->createStub(ConfigurableResource::class);
        $configurable->method('getParentIdsByChild')->willReturnCallback(
            static fn ($id) => $id === 1 ? ['77'] : []
        );

        $builder = $this->builder(
            [
                $this->collection([$this->product('S1', 'simple', 1), $this->product('S2', 'simple', 2), $this->product('V', 'virtual', 3)]),
                $this->collection([], $this->product('P', 'configurable', 77)),
            ],
            $this->connection($fields),
            null,
            null,
            null,
            $configurable
        );

        $result = $builder->generate(['feed_id' => 2, 'store_id' => 1, 'output_format' => 'csv']);

        $this->assertSame(3, $result['product_count']);
        $csv = (string) file_get_contents($result['file_path']);
        $this->assertStringContainsString('S1,parent-P', $csv);
        $this->assertStringContainsString('S2,own', $csv);
        $this->assertStringContainsString('V,own', $csv);
        $idFilters = array_values(array_filter($this->collectionCalls, static fn ($c) => $c[0] === 'addIdFilter'));
        $this->assertSame(77, $idFilters[0][1][0]);
    }

    public function testProductFailureDeliveryFailureAndStatsFailureAreLogged(): void
    {
        $resolver = $this->createStub(FieldResolver::class);
        $resolver->method('resolve')->willReturnCallback(static function (array $config, Product $product) {
            if ($product->getSku() === 'BAD') {
                throw new \RuntimeException('resolver broke');
            }
            return 'v';
        });

        $delivery = $this->createStub(FtpDelivery::class);
        $delivery->method('deliver')->willThrowException(new \RuntimeException('refused'));

        $warnings = [];
        $errors = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function ($msg) use (&$warnings): void {
            $warnings[] = $msg;
        });
        $logger->method('error')->willReturnCallback(static function ($msg) use (&$errors): void {
            $errors[] = $msg;
        });

        $builder = $this->builder(
            [$this->collection([$this->product('BAD'), $this->product('OK')])],
            $this->connection(self::fields(), [], false, true, true),
            $resolver,
            $logger,
            $delivery
        );

        $result = $builder->generate([
            'feed_id' => 11,
            'store_id' => 1,
            'output_format' => 'csv',
            'delivery_enabled' => 1,
            'compress' => 'none',
        ]);

        $this->assertSame(1, $result['product_count']);
        $this->assertStringEndsWith('feed_11.csv', $result['file_path']);
        $this->assertSame([
            'Panth SEO Feed: failed to process product SKU "BAD" for feed #11: resolver broke',
            'Panth SEO Feed: failed to update stats for profile #11: locked',
        ], $warnings);
        $this->assertSame(['Panth SEO Feed: FTP/SFTP delivery failed for profile #11: refused'], $errors);
    }
}
