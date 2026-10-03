<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Ui\Component\Listing\Column;

use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Ui\Component\Listing\Column\CustomCanonicalActions;
use Panth\AdvancedSEO\Ui\Component\Listing\Column\FeedActions;
use Panth\AdvancedSEO\Ui\Component\Listing\Column\FeedFieldActions;
use Panth\AdvancedSEO\Ui\Component\Listing\Column\FeedFileLink;
use Panth\AdvancedSEO\Ui\Component\Listing\Column\MissingMetaActions;
use Panth\AdvancedSEO\Ui\Component\Listing\Column\MissingMetaHighlight;
use Panth\AdvancedSEO\Ui\Component\Listing\Column\RuleActions;
use Panth\AdvancedSEO\Ui\Component\Listing\Column\StatusCodeColumn;
use Panth\AdvancedSEO\Ui\Component\Listing\Column\TemplateActions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ColumnsTest extends TestCase
{
    private function url(): UrlInterface
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn($route = null, $params = []) => '/' . $route . ($params ? '?' . http_build_query($params) : '')
        );
        return $url;
    }

    private function args(): array
    {
        return [$this->createStub(ContextInterface::class), $this->createStub(UiComponentFactory::class)];
    }

    private static function source(array $items): array
    {
        return ['data' => ['items' => $items]];
    }

    public static function actionColumns(): array
    {
        return [
            'canonical' => [CustomCanonicalActions::class, 'canonical_id', ['edit' => '/panth_seo/customcanonical/edit?id=5', 'delete' => '/panth_seo/customcanonical/delete?id=5']],
            'rule'      => [RuleActions::class, 'rule_id', ['edit' => '/panth_seo/rule/edit?id=5', 'delete' => '/panth_seo/rule/delete?id=5']],
            'template'  => [TemplateActions::class, 'template_id', [
                'edit' => '/panth_seo/template/edit?id=5', 'apply' => '/panth_seo/template/apply?template_id=5', 'delete' => '/panth_seo/template/delete?id=5',
            ]],
            'feed'      => [FeedActions::class, 'feed_id', [
                'edit' => '/panth_seo/feed/edit?id=5', 'fields' => '/panth_seo/feed/fields?feed_id=5',
                'generate' => '/panth_seo/feed/generate?id=5', 'delete' => '/panth_seo/feed/delete?id=5',
            ]],
        ];
    }

    #[DataProvider('actionColumns')]
    public function testActionLinksAreBuiltForRowsWithAnId(string $class, string $idField, array $expected): void
    {
        [$context, $factory] = $this->args();
        $column = new $class($context, $factory, $this->url(), [], ['name' => 'actions']);

        $result = $column->prepareDataSource(self::source([[$idField => 5], ['other' => 1]]));

        $links = $result['data']['items'][0]['actions'];
        $this->assertSame($expected, array_map(static fn(array $link): string => $link['href'], $links));
        $this->assertTrue($links['delete']['post']);
        $this->assertArrayHasKey('message', $links['delete']['confirm']);
        $this->assertArrayNotHasKey('actions', $result['data']['items'][1]);
        $this->assertSame(['data' => []], $column->prepareDataSource(['data' => []]));
    }

    public function testFeedActionsOfferADownloadWhenAFileExists(): void
    {
        [$context, $factory] = $this->args();
        $column = new FeedActions($context, $factory, $this->url(), [], ['name' => 'actions']);

        $result = $column->prepareDataSource(self::source([['feed_id' => 1, 'file_url' => 'https://m/f.xml']]));

        $this->assertSame(['href' => 'https://m/f.xml', 'label' => 'Download', 'target' => '_blank'], $result['data']['items'][0]['actions']['download']);
        $this->assertSame(['edit', 'fields', 'generate', 'download', 'delete'], array_keys($result['data']['items'][0]['actions']));
    }

    public function testFeedFieldActionsCarryBothIds(): void
    {
        [$context, $factory] = $this->args();
        $column = new FeedFieldActions($context, $factory, $this->url(), [], ['name' => 'actions']);

        $result = $column->prepareDataSource(self::source([['field_id' => 3, 'feed_id' => 9], ['feed_id' => 9]]));

        $this->assertSame('/panth_seo/feed/newField?field_id=3&feed_id=9', $result['data']['items'][0]['actions']['edit']['href']);
        $this->assertSame('/panth_seo/feed/deleteField?field_id=3&feed_id=9', $result['data']['items'][0]['actions']['delete']['href']);
        $this->assertArrayNotHasKey('actions', $result['data']['items'][1]);
        $this->assertSame([], $column->prepareDataSource([]));
    }

    public function testFeedFileLinkPointsToTheStoreMediaFolder(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/media/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $requested = [];
        $storeManager->method('getStore')->willReturnCallback(static function ($id) use ($store, &$requested) {
            $requested[] = $id;
            if ($id === 7) {
                throw new \RuntimeException('no store');
            }
            return $store;
        });
        [$context, $factory] = $this->args();
        $column = new FeedFileLink($context, $factory, $storeManager, [], ['name' => 'file']);

        $result = $column->prepareDataSource(self::source([
            ['file' => 'a&b.xml', 'store_id' => 0],
            ['file' => ''],
            ['file' => 'x.xml', 'store_id' => 7],
            ['file' => 'y.xml', 'store_id' => 2],
        ]));

        $this->assertSame(
            '<a href="https://shop.test/media/panth_seo/feeds/a&amp;b.xml" target="_blank" style="color:#006bb4;text-decoration:underline;">a&amp;b.xml</a>',
            $result['data']['items'][0]['file']
        );
        $this->assertSame('', $result['data']['items'][1]['file']);
        $this->assertSame('x.xml', $result['data']['items'][2]['file']);
        $this->assertSame([1, 7, 2], $requested);
    }

    private function missingMetaActions(?string $type): MissingMetaActions
    {
        $session = $this->createStub(BackendSession::class);
        $session->method('getData')->willReturnCallback(static fn($key = '') => $key === 'panth_seo_missing_meta_type' ? $type : null);
        [$context, $factory] = $this->args();
        return new MissingMetaActions($context, $factory, $this->url(), $session, [], ['name' => 'actions']);
    }

    public function testMissingMetaActionsLinkToTheRightEditor(): void
    {
        $products = $this->missingMetaActions(null)->prepareDataSource(self::source([['entity_id' => 4], ['entity_id' => 0]]));
        $this->assertSame('/catalog/product/edit?id=4', $products['data']['items'][0]['actions']['edit']['href']);
        $this->assertSame('Edit Product', (string) $products['data']['items'][0]['actions']['edit']['label']);
        $this->assertArrayNotHasKey('actions', $products['data']['items'][1]);

        $categories = $this->missingMetaActions('category')->prepareDataSource(self::source([['entity_id' => 6]]));
        $this->assertSame('/catalog/category/edit?id=6', $categories['data']['items'][0]['actions']['edit']['href']);

        $this->assertSame(['x' => 1], $this->missingMetaActions('product')->prepareDataSource(['x' => 1]));
    }

    public function testMissingMetaHighlightFlagsEmptyValuesAndEscapesOthers(): void
    {
        [$context, $factory] = $this->args();
        $column = new MissingMetaHighlight($context, $factory, [], ['name' => 'meta_title']);

        $result = $column->prepareDataSource(self::source([['meta_title' => '  '], ['meta_title' => '<b>Tea & "Co"</b>'], []]));

        $this->assertStringContainsString('MISSING', $result['data']['items'][0]['meta_title']);
        $this->assertSame('&lt;b&gt;Tea &amp; &quot;Co&quot;&lt;/b&gt;', $result['data']['items'][1]['meta_title']);
        $this->assertStringContainsString('MISSING', $result['data']['items'][2]['meta_title']);
        $this->assertSame([], $column->prepareDataSource([]));
    }

    public static function statusCodes(): array
    {
        return [
            [200, '#185b00'],
            [301, '#7a5800'],
            [404, '#b30000'],
            [503, '#b30000'],
            [0, '#333333'],
            [102, '#333333'],
        ];
    }

    #[DataProvider('statusCodes')]
    public function testStatusCodesAreColoured(int $code, string $colour): void
    {
        [$context, $factory] = $this->args();
        $column = new StatusCodeColumn($context, $factory, [], ['name' => 'status_code']);

        $item = $column->prepareDataSource(self::source([['status_code' => (string) $code]]))['data']['items'][0];

        $expected = sprintf('<span style="color:%s;font-weight:600;">%d</span>', $colour, $code);
        $this->assertSame($expected, $item['status_code']);
        $this->assertSame($expected, $item['status_code_html']);
    }
}
