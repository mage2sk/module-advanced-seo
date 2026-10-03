<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Block\Adminhtml;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\DataObject;
use Magento\Framework\UrlInterface;
use Panth\AdvancedSEO\Block\Adminhtml\BulkEditor;
use Panth\AdvancedSEO\Block\Adminhtml\Feed\FieldInfo;
use Panth\AdvancedSEO\Test\Unit\Block\BackendTemplateObjectManager;
use PHPUnit\Framework\TestCase;

class BulkEditorTest extends TestCase
{
    use BackendTemplateObjectManager;

    private array $paging = [];

    private function context(array $params = []): Context
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(static fn($route = null) => 'https://admin.test/' . $route);
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $params[$key] ?? $default);
        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($url);
        $context->method('getRequest')->willReturn($request);
        return $context;
    }

    public function testProductsAreMappedToEditorRows(): void
    {
        $this->paging = [];
        $collection = $this->createStub(Collection::class);
        $collection->method('setPageSize')->willReturnCallback(function ($size) use ($collection) {
            $this->paging['size'] = $size;
            return $collection;
        });
        $collection->method('setCurPage')->willReturnCallback(function ($page) use ($collection) {
            $this->paging['page'] = $page;
            return $collection;
        });
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            new DataObject(['id' => '3', 'name' => 'Bottle', 'sku' => 'B1', 'meta_title' => 'T', 'meta_description' => null, 'meta_keyword' => 'k']),
        ]));
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $block = new BulkEditor($this->context(), $factory);
        $rows = $block->getProducts(20, 3);

        $this->assertSame(['size' => 20, 'page' => 3], $this->paging);
        $this->assertSame([[
            'entity_id' => 3, 'name' => 'Bottle', 'sku' => 'B1', 'meta_title' => 'T', 'meta_description' => '', 'meta_keyword' => 'k',
        ]], $rows);
        $this->assertSame('https://admin.test/panth_seo/bulkeditor/save', $block->getSaveUrl());
        $this->assertSame('https://admin.test/panth_seo/bulkeditor/massGenerate', $block->getGenerateUrl());
    }

    public function testFieldInfoPrefersTheRequestFeedId(): void
    {
        $session = $this->createStub(BackendSession::class);
        $session->method('getData')->willReturnCallback(static fn($key = '') => $key === 'panth_seo_feed_field_feed_id' ? '8' : null);

        $this->assertSame(4, (new FieldInfo($this->context(['feed_id' => '4']), $session))->getFeedId());
        $this->assertSame(8, (new FieldInfo($this->context(), $session))->getFeedId());
    }
}
