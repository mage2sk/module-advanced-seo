<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Model\Audit\SitemapLocator;
use PHPUnit\Framework\TestCase;

class SitemapLocatorTest extends TestCase
{
    private function locator(?array $rows, bool $hasFilenameColumn = true, bool $throws = false): SitemapLocator
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn($rows !== null);
        $connection->method('tableColumnExists')->willReturn($hasFilenameColumn);
        $connection->method('select')->willReturn($select);
        if ($throws) {
            $connection->method('fetchAll')->willThrowException(new \RuntimeException('db'));
        } else {
            $connection->method('fetchAll')->willReturn($rows ?? []);
        }
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $store = $this->createStub(Store::class);
        $store->method('getCode')->willReturn('luma');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new SitemapLocator($resource, $storeManager);
    }

    public function testConfiguredProfilePathAndFilenameAreUsed(): void
    {
        $urls = $this->locator([
            ['output_path' => '/', 'sitemap_filename' => 'sitemap_luma.xml'],
            ['output_path' => 'xmlsitemap/{store_code}/', 'sitemap_filename' => ''],
            ['output_path' => '/', 'sitemap_filename' => 'sitemap_luma.xml'],
        ])->getSitemapUrls(2, 'https://luma.test');

        $this->assertSame(
            ['https://luma.test/sitemap_luma.xml', 'https://luma.test/xmlsitemap/luma/sitemap.xml'],
            $urls
        );
    }

    public function testFallsBackToSitemapXml(): void
    {
        $this->assertSame(['https://shop.test/sitemap.xml'], $this->locator(null)->getSitemapUrls(1, 'https://shop.test/'));
        $this->assertSame(['https://shop.test/sitemap.xml'], $this->locator([])->getSitemapUrls(1, 'https://shop.test/'));
        $this->assertSame(['https://shop.test/sitemap.xml'], $this->locator([], true, true)->getSitemapUrls(1, 'https://shop.test/'));
    }

    public function testOlderProfileTablesWithoutTheFilenameColumnUseTheDefaultName(): void
    {
        $this->assertSame(
            ['https://shop.test/sitemaps/sitemap.xml'],
            $this->locator([['output_path' => 'sitemaps']], false)->getSitemapUrls(1, 'https://shop.test/')
        );
    }

    public function testUnsafePathPartsAndFilenamesAreIgnored(): void
    {
        $locator = $this->locator([]);

        $this->assertSame('a/b/sitemap.xml', $locator->relativePath('../a/./b/', '../../etc/passwd', 'default'));
        $this->assertSame('sitemap.xml', $locator->relativePath('', 'sitemap.php', 'default'));
        $this->assertSame('x/default/s-1.xml', $locator->relativePath('x/{store_code}', 's-1.xml', 'default'));
        $this->assertSame('sitemap.xml', $locator->relativePath('/', '', 'default'));
    }
}
