<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Observer\SearchConsole;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\SearchConsole\IndexingClient;
use Panth\AdvancedSEO\Observer\SearchConsole\EntityChangeObserver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EntityChangeObserverTest extends TestCase
{
    private array $submitted = [];

    private array $errors = [];

    protected function setUp(): void
    {
        $this->setStatic('pendingUrls', []);
        $this->setStatic('shutdownRegistered', true);
    }

    protected function tearDown(): void
    {
        $this->setStatic('pendingUrls', []);
        $this->setStatic('clientRef', null);
        $this->setStatic('loggerRef', null);
    }

    private function setStatic(string $name, mixed $value): void
    {
        $property = new \ReflectionProperty(EntityChangeObserver::class, $name);
        $property->setValue(null, $value);
    }

    private function pending(): array
    {
        return (new \ReflectionProperty(EntityChangeObserver::class, 'pendingUrls'))->getValue();
    }

    private function observer(bool $enabled = true, bool $clientEnabled = true, array $failing = []): EntityChangeObserver
    {
        $this->submitted = [];
        $this->errors = [];

        $client = $this->createStub(IndexingClient::class);
        $client->method('isEnabled')->willReturn($clientEnabled);
        $client->method('submitUrl')->willReturnCallback(function (string $url, string $type) use ($failing) {
            if (in_array($url, $failing, true)) {
                throw new \RuntimeException('quota');
            }
            $this->submitted[] = [$url, $type];
            return [];
        });

        $defaultStore = $this->createStub(Store::class);
        $defaultStore->method('getId')->willReturn(1);
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($defaultStore);
        $storeManager->method('getStore')->willReturnCallback(static function ($id) use ($store) {
            if ($id === 99) {
                throw new \RuntimeException('no store');
            }
            return $store;
        });

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
            $this->errors[] = [$message, $context];
        });

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        return new EntityChangeObserver($client, $storeManager, $logger, $config);
    }

    private static function event(array $data): Observer
    {
        return new Observer(['event' => new Event($data)]);
    }

    private function productStub(?int $id, string|\Throwable $url): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn($id);
        if ($url instanceof \Throwable) {
            $product->method('getProductUrl')->willThrowException($url);
        } else {
            $product->method('getProductUrl')->willReturn($url);
        }
        return $product;
    }

    private function pageStub(?int $id, mixed $storeIds, string $identifier = 'about-us'): Page
    {
        $page = new class extends Page {
            public function __construct()
            {
            }
        };
        $page->setData(['page_id' => $id, 'store_id' => $storeIds, 'identifier' => $identifier]);
        return $page;
    }

    public function testChangedEntitiesAreQueuedAndFlushedOnce(): void
    {
        $observer = $this->observer();
        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn(3);
        $category->method('getUrl')->willReturn('https://shop.test/cat.html');

        $observer->execute(self::event(['product' => $this->productStub(1, 'https://shop.test/p.html')]));
        $observer->execute(self::event(['product' => $this->productStub(1, 'https://shop.test/p.html')]));
        $observer->execute(self::event(['category' => $category]));
        $observer->execute(self::event(['object' => $this->pageStub(4, [2], '/about-us')]));

        $this->assertSame(
            ['https://shop.test/p.html', 'https://shop.test/p.html', 'https://shop.test/cat.html', 'https://shop.test/about-us'],
            $this->pending()
        );

        EntityChangeObserver::flushPendingUrls();

        $this->assertSame([
            ['https://shop.test/p.html', 'URL_UPDATED'],
            ['https://shop.test/cat.html', 'URL_UPDATED'],
            ['https://shop.test/about-us', 'URL_UPDATED'],
        ], $this->submitted);
        $this->assertSame([], $this->pending());
    }

    public function testAllStoreViewsPageUsesTheDefaultStore(): void
    {
        $this->observer()->execute(self::event(['object' => $this->pageStub(4, 0, 'home')]));

        $this->assertSame(['https://shop.test/home'], $this->pending());
    }

    public function testUnsavedEntitiesAndBrokenUrlsAreSkipped(): void
    {
        $observer = $this->observer();
        $observer->execute(self::event(['product' => $this->productStub(null, 'x')]));
        $observer->execute(self::event(['product' => $this->productStub(2, new \RuntimeException('no url'))]));
        $observer->execute(self::event(['object' => $this->pageStub(null, [1])]));
        $observer->execute(self::event(['object' => $this->pageStub(5, [99])]));
        $observer->execute(self::event(['object' => new \stdClass()]));
        $observer->execute(self::event([]));

        $this->assertSame([], $this->pending());
    }

    public function testDisabledModuleOrClientQueuesNothing(): void
    {
        $this->observer(false)->execute(self::event(['product' => $this->productStub(1, 'https://a')]));
        $this->observer(true, false)->execute(self::event(['product' => $this->productStub(1, 'https://a')]));

        $this->assertSame([], $this->pending());
    }

    public function testAFailedSubmitIsLoggedAndTheRestContinue(): void
    {
        $this->observer(true, true, ['https://a']);
        $this->setStatic('pendingUrls', ['https://a', 'https://b']);

        EntityChangeObserver::flushPendingUrls();

        $this->assertSame([['https://b', 'URL_UPDATED']], $this->submitted);
        $this->assertSame(
            ['Panth SEO Indexing API flush failed.', ['error' => 'quota', 'url' => 'https://a']],
            $this->errors[0]
        );
    }

    public function testFlushWithoutAClientIsANoop(): void
    {
        $this->setStatic('clientRef', null);
        $this->setStatic('pendingUrls', ['https://a']);

        EntityChangeObserver::flushPendingUrls();

        $this->assertSame(['https://a'], $this->pending());
    }
}
