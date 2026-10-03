<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Category\DataProvider as CategoryDataProvider;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Page\Title;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\Fix\HreflangGroupValidator;
use Panth\AdvancedSEO\Plugin\Admin\CategorySerpPreviewPlugin;
use Panth\AdvancedSEO\Plugin\Hreflang\GroupGuardPlugin;
use Panth\AdvancedSEO\Plugin\Meta\StripTitlePrefixSuffix;
use Panth\AdvancedSEO\Plugin\Url\ShortCategoryUrlPlugin;
use PHPUnit\Framework\TestCase;

class SmallPluginsTest extends TestCase
{
    private array $messages = [];

    private array $redirects = [];

    private function config(bool $enabled = true, bool $fix = true): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('isAuditFixEnabled')->willReturn($fix);
        return $config;
    }

    private function scopeConfig(array $values = [], array $flags = []): ScopeConfigInterface
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn(string $path, $scope = null, $storeId = null) => $values[$path . '@' . $storeId] ?? $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(static fn(string $path): bool => $flags[$path] ?? false);
        return $scopeConfig;
    }

    public function testCategorySerpPreviewUsesTheExistingSeoGroupKey(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $plugin = new CategorySerpPreviewPlugin($storeManager, $this->config());
        $subject = $this->createStub(CategoryDataProvider::class);

        $underscored = $plugin->afterGetMeta($subject, ['search_engine_optimization' => ['children' => []]]);
        $config = $underscored['search_engine_optimization']['children']['panth_seo_serp_preview']['arguments']['data']['config'];
        $this->assertSame('category', $config['entityType']);
        $this->assertSame('https://shop.test', $config['baseUrl']);
        $this->assertArrayNotHasKey('search-engine-optimization', $underscored);

        $dashed = $plugin->afterGetMeta($subject, []);
        $this->assertArrayHasKey('panth_seo_serp_preview', $dashed['search-engine-optimization']['children']);

        $disabled = new CategorySerpPreviewPlugin($storeManager, $this->config(false));
        $this->assertSame(['x' => 1], $disabled->afterGetMeta($subject, ['x' => 1]));
    }

    private function category(int $level, string $urlKey, int $storeId = 1): CategoryInterface
    {
        $category = $this->createStub(Category::class);
        $category->method('getLevel')->willReturn($level);
        $category->method('getUrlKey')->willReturn($urlKey);
        $category->method('getStoreId')->willReturn($storeId);
        return $category;
    }

    public function testShortCategoryUrlUsesOnlyTheUrlKey(): void
    {
        $plugin = new ShortCategoryUrlPlugin(
            $this->scopeConfig([], ['panth_seo/canonical/use_short_category_url' => true]),
            $this->config()
        );
        $subject = $this->createStub(CategoryUrlPathGenerator::class);
        $proceed = static fn(CategoryInterface $c): string => 'parent/' . $c->getUrlKey();

        $this->assertSame('shirts', $plugin->aroundGetUrlPath($subject, $proceed, $this->category(3, 'shirts')));
        $this->assertSame('parent/root', $plugin->aroundGetUrlPath($subject, $proceed, $this->category(1, 'root')));
        $this->assertSame('parent/', $plugin->aroundGetUrlPath($subject, $proceed, $this->category(3, '', 0)));
    }

    public function testShortCategoryUrlIsOffByDefault(): void
    {
        $plugin = new ShortCategoryUrlPlugin($this->scopeConfig(), $this->config());
        $subject = $this->createStub(CategoryUrlPathGenerator::class);

        $this->assertSame('full/path', $plugin->aroundGetUrlPath($subject, static fn() => 'full/path', $this->category(3, 'shirts')));

        $plugin = new ShortCategoryUrlPlugin(
            $this->scopeConfig([], ['panth_seo/canonical/use_short_category_url' => true]),
            $this->config(false)
        );
        $this->assertSame('full/path', $plugin->aroundGetUrlPath($subject, static fn() => 'full/path', $this->category(3, 'shirts')));
    }

    private function stripPlugin(string|\Throwable $area, bool $flag = true, bool $enabled = true): StripTitlePrefixSuffix
    {
        $state = $this->createStub(State::class);
        if ($area instanceof \Throwable) {
            $state->method('getAreaCode')->willThrowException($area);
        } else {
            $state->method('getAreaCode')->willReturn($area);
        }

        return new StripTitlePrefixSuffix(
            $this->scopeConfig(
                ['design/head/title_prefix' => 'Shop -', 'design/head/title_suffix' => '- Brand'],
                ['panth_seo/meta/strip_title_prefix_suffix' => $flag]
            ),
            $state,
            $this->config($enabled)
        );
    }

    public function testMetaTitleStripperRemovesAffixesOnTheFrontendOnly(): void
    {
        $title = $this->createStub(Title::class);

        $this->assertSame('Shirts', $this->stripPlugin('frontend')->afterGet($title, 'Shop - Shirts - Brand'));
        $this->assertSame('Shop - Shirts', $this->stripPlugin('adminhtml')->afterGet($title, 'Shop - Shirts'));
        $this->assertSame('Shop - Shirts', $this->stripPlugin(new \RuntimeException('no area'))->afterGet($title, 'Shop - Shirts'));
        $this->assertSame('Shop - Shirts', $this->stripPlugin('frontend', false)->afterGet($title, 'Shop - Shirts'));
        $this->assertSame('Shop - Shirts', $this->stripPlugin('frontend', true, false)->afterGet($title, 'Shop - Shirts'));
    }

    private function guard(mixed $members, int $groupId = 0, bool $fix = true): mixed
    {
        $this->messages = [];
        $this->redirects = [];

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn(string $key) => ['hreflang_members' => $members, 'group_id' => $groupId][$key] ?? null
        );
        $messageManager = $this->createStub(ManagerInterface::class);
        $messageManager->method('addErrorMessage')->willReturnCallback(function ($message) use ($messageManager) {
            $this->messages[] = (string) $message;
            return $messageManager;
        });
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function (string $path, array $params = []) use ($redirect) {
            $this->redirects[] = [$path, $params];
            return $redirect;
        });
        $factory = $this->createStub(RedirectFactory::class);
        $factory->method('create')->willReturn($redirect);

        $plugin = new GroupGuardPlugin(
            $this->config(true, $fix),
            new HreflangGroupValidator(),
            $request,
            $messageManager,
            $factory,
            $this->scopeConfig(['general/locale/code@1' => 'en_GB', 'general/locale/code@2' => 'en_GB', 'general/locale/code@3' => 'fr_FR'])
        );

        return $plugin->aroundExecute(null, static fn() => 'saved');
    }

    public function testHreflangGroupWithUniqueLocalesIsSaved(): void
    {
        $this->assertSame('saved', $this->guard([
            ['store_id' => 1, 'entity_id' => 10],
            ['store_id' => 3, 'entity_id' => 11],
        ]));
        $this->assertSame('saved', $this->guard('not-an-array'));
        $this->assertSame('saved', $this->guard([['store_id' => 1, 'entity_id' => 1], ['store_id' => 2, 'entity_id' => 2]], 0, false));
        $this->assertSame([], $this->messages);
    }

    public function testDuplicateLocalesBlockTheSaveAndRedirect(): void
    {
        $result = $this->guard([
            ['store_id' => 1, 'entity_id' => 10],
            ['store_id' => 2, 'entity_id' => 11],
        ], 5);

        $this->assertNotSame('saved', $result);
        $this->assertCount(1, $this->messages);
        $this->assertStringContainsString('2 members use the locale "en-gb" (store:entity 1:10, 2:11)', $this->messages[0]);
        $this->assertSame([['*/*/edit', ['id' => 5]]], $this->redirects);

        $this->guard([['store_id' => 1, 'entity_id' => 10], ['store_id' => 2, 'entity_id' => 11]]);
        $this->assertSame([['*/*/', []]], $this->redirects);
    }
}
